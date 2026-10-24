<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\OAuth\Jwt;
use Portier\Passkeys\AuthenticatorData;
use Portier\Passkeys\Cbor;
use Portier\Passkeys\CoseKey;
use Portier\Passkeys\PasskeyException;
use Portier\Passkeys\RelyingParty;
use Portier\Tests\Support\CborBytes;
use Portier\Tests\Support\FakeAuthenticator;

/**
 * @covers \Portier\Passkeys\Cbor
 * @covers \Portier\Passkeys\CoseKey
 * @covers \Portier\Passkeys\AuthenticatorData
 * @covers \Portier\Passkeys\RelyingParty
 */
final class PasskeyCryptoTest extends TestCase
{
    private const ORIGIN = 'https://example.org';
    private const RP = 'example.org';

    private function rp(): RelyingParty
    {
        return new RelyingParty(self::RP, 'Example', [self::ORIGIN]);
    }

    private function reason(callable $call): string
    {
        try {
            $call();
        } catch (PasskeyException $exception) {
            return $exception->reason;
        }
        return 'accepted';
    }

    // ------------------------------------------------------------------ CBOR

    public function test_cbor_decodes_what_webauthn_uses(): void
    {
        $data = FakeAuthenticator::cbor([
            'fmt' => 'none',
            'big' => 70000,
            'neg' => -300,
            'bytes' => new CborBytes("\x00\xff"),
            'list' => [1, true, false, null],
            'map' => [-1 => 6],
        ]);
        $this->assertSame(
            ['fmt' => 'none', 'big' => 70000, 'neg' => -300, 'bytes' => "\x00\xff", 'list' => [1, true, false, null], 'map' => [-1 => 6]],
            Cbor::decode($data)
        );
    }

    public function test_cbor_refuses_hostile_or_unsupported_input(): void
    {
        $cases = [
            'trailing bytes' => "\x01\x01",
            'truncated string' => "\x45ab",
            'length beyond input' => "\x5a\xff\xff\xff\xff",
            'indefinite length' => "\x5f",
            'tag' => "\xc0\x01",
            'float' => "\xf9\x3c\x00",
            'duplicate key' => "\xa2\x01\x01\x01\x02",
            'array key' => "\xa1\x80\x01",
            'too deep' => str_repeat("\x81", 40) . "\x01",
            'empty' => '',
        ];
        foreach ($cases as $label => $bytes) {
            $this->assertSame(PasskeyException::MALFORMED, $this->reason(static fn () => Cbor::decode($bytes)), $label);
        }
    }

    // ------------------------------------------------------------------ Keys

    public function test_all_three_key_types_verify_their_signatures(): void
    {
        foreach ([CoseKey::ES256, CoseKey::EDDSA, CoseKey::RS256] as $algorithm) {
            $authenticator = new FakeAuthenticator($algorithm);
            $key = CoseKey::fromCose(Cbor::decode(FakeAuthenticator::cbor($authenticator->coseKey())));
            $this->assertTrue($key->verify('payload', $authenticator->sign('payload')), (string) $algorithm);
            $this->assertFalse($key->verify('other payload', $authenticator->sign('payload')), (string) $algorithm);
            $this->assertFalse($key->verify('payload', ''), (string) $algorithm);

            // Stored and restored, it still verifies.
            $restored = CoseKey::import($key->export());
            $this->assertTrue($restored->verify('payload', $authenticator->sign('payload')), (string) $algorithm);
        }
    }

    public function test_weak_or_unknown_keys_are_refused(): void
    {
        // A point that is not on the curve.
        $this->assertSame(PasskeyException::MALFORMED, $this->reason(static fn () => CoseKey::fromCose(
            [1 => 2, 3 => CoseKey::ES256, -1 => 1, -2 => str_repeat("\x01", 32), -3 => str_repeat("\x02", 32)]
        )));
        // RSA below 2048 bits.
        $this->assertSame(PasskeyException::ALGORITHM, $this->reason(static fn () => CoseKey::fromCose(
            [1 => 3, 3 => CoseKey::RS256, -1 => str_repeat("\xff", 128), -2 => "\x01\x00\x01"]
        )));
        // Algorithms the plugin does not offer (e.g. ES384).
        $this->assertSame(PasskeyException::ALGORITHM, $this->reason(static fn () => CoseKey::fromCose([1 => 2, 3 => -35, -1 => 2])));
        $this->assertSame(PasskeyException::MALFORMED, $this->reason(static fn () => CoseKey::fromCose('not a map')));
    }

    // ------------------------------------------------------------------ Registration

    public function test_a_valid_registration_is_accepted(): void
    {
        $authenticator = new FakeAuthenticator();
        $response = $authenticator->register('challenge-1', self::ORIGIN, self::RP)['response'];
        $data = $this->rp()->verifyRegistration(
            Jwt::base64UrlDecode($response['clientDataJSON']),
            Jwt::base64UrlDecode($response['attestationObject']),
            'challenge-1'
        );
        $this->assertSame($authenticator->credentialId, $data->credentialId);
        $this->assertNotNull($data->publicKey);
        $this->assertTrue($data->has(AuthenticatorData::USER_VERIFIED));
    }

    public function test_registrations_for_another_site_or_without_verification_are_refused(): void
    {
        $register = function (string $challenge, string $origin, string $rp, int $flags, string $expected): string {
            $response = (new FakeAuthenticator())->register($challenge, $origin, $rp, $flags)['response'];
            return $this->reason(fn () => $this->rp()->verifyRegistration(
                Jwt::base64UrlDecode($response['clientDataJSON']),
                Jwt::base64UrlDecode($response['attestationObject']),
                $expected
            ));
        };
        $both = AuthenticatorData::USER_PRESENT | AuthenticatorData::USER_VERIFIED;
        $this->assertSame(PasskeyException::CHALLENGE, $register('other', self::ORIGIN, self::RP, $both, 'challenge-1'));
        $this->assertSame(PasskeyException::ORIGIN, $register('challenge-1', 'https://examp1e.org', self::RP, $both, 'challenge-1'), 'Phishing origin.');
        $this->assertSame(PasskeyException::ORIGIN, $register('challenge-1', self::ORIGIN, 'evil.example', $both, 'challenge-1'), 'Other RP ID.');
        $this->assertSame(PasskeyException::VERIFICATION, $register('challenge-1', self::ORIGIN, self::RP, AuthenticatorData::USER_PRESENT, 'challenge-1'), 'No PIN/biometrics.');
        $this->assertSame(PasskeyException::VERIFICATION, $register('challenge-1', self::ORIGIN, self::RP, AuthenticatorData::USER_VERIFIED, 'challenge-1'), 'Nobody present.');
    }

    // ------------------------------------------------------------------ Login

    public function test_a_valid_login_is_accepted_and_the_counter_moves_on(): void
    {
        foreach ([CoseKey::ES256, CoseKey::EDDSA, CoseKey::RS256] as $algorithm) {
            $authenticator = new FakeAuthenticator($algorithm);
            $key = CoseKey::fromCose(Cbor::decode(FakeAuthenticator::cbor($authenticator->coseKey())));
            $authenticator->counter = 7;
            $response = $authenticator->login('challenge-2', self::ORIGIN, self::RP, 'handle')['response'];
            $count = $this->rp()->verifyAssertion(
                Jwt::base64UrlDecode($response['clientDataJSON']),
                Jwt::base64UrlDecode($response['authenticatorData']),
                Jwt::base64UrlDecode($response['signature']),
                'challenge-2',
                $key,
                3
            );
            $this->assertSame(7, $count, (string) $algorithm);
        }
    }

    public function test_tampered_cloned_or_misdirected_logins_are_refused(): void
    {
        $authenticator = new FakeAuthenticator();
        $key = CoseKey::fromCose(Cbor::decode(FakeAuthenticator::cbor($authenticator->coseKey())));
        $check = function (array $response, int $stored = 0, string $challenge = 'challenge-3') use ($key): string {
            return $this->reason(fn () => $this->rp()->verifyAssertion(
                Jwt::base64UrlDecode($response['clientDataJSON']),
                Jwt::base64UrlDecode($response['authenticatorData']),
                Jwt::base64UrlDecode($response['signature']),
                $challenge,
                $key,
                $stored
            ));
        };

        $valid = $authenticator->login('challenge-3', self::ORIGIN, self::RP, 'h')['response'];
        $this->assertSame('accepted', $check($valid), 'Counter 0 on both sides (synced passkeys).');
        $this->assertSame(PasskeyException::CHALLENGE, $check($valid, 0, 'challenge-4'));

        // Another key signed it.
        $forged = $valid;
        $forged['signature'] = Jwt::base64UrlEncode((new FakeAuthenticator(CoseKey::RS256))->sign('x'));
        $this->assertSame(PasskeyException::SIGNATURE, $check($forged));

        // Flags changed after signing.
        $tampered = $valid;
        $data = Jwt::base64UrlDecode($valid['authenticatorData']);
        $data[32] = chr(AuthenticatorData::USER_PRESENT | AuthenticatorData::USER_VERIFIED | AuthenticatorData::BACKED_UP);
        $tampered['authenticatorData'] = Jwt::base64UrlEncode($data);
        $this->assertSame(PasskeyException::SIGNATURE, $check($tampered));

        // Cloned key: the counter goes backwards.
        $authenticator->counter = 5;
        $this->assertSame(PasskeyException::COUNTER, $check($authenticator->login('challenge-3', self::ORIGIN, self::RP, 'h')['response'], 9));

        // A registration response replayed as login, a phishing origin, a cross-origin frame.
        $authenticator->counter = 0;
        $this->assertSame(PasskeyException::MALFORMED, $check($authenticator->login('challenge-3', self::ORIGIN, self::RP, 'h', AuthenticatorData::USER_PRESENT | AuthenticatorData::USER_VERIFIED, 'webauthn.create')['response']));
        $this->assertSame(PasskeyException::ORIGIN, $check($authenticator->login('challenge-3', 'https://evil.example', self::RP, 'h')['response']));
        $cross = $valid;
        $cross['clientDataJSON'] = Jwt::base64UrlEncode(FakeAuthenticator::clientData('webauthn.get', 'challenge-3', self::ORIGIN, true));
        $this->assertSame(PasskeyException::ORIGIN, $check($cross));

        // No user verification.
        $this->assertSame(PasskeyException::VERIFICATION, $check($authenticator->login('challenge-3', self::ORIGIN, self::RP, 'h', AuthenticatorData::USER_PRESENT)['response']));
    }

    public function test_options_ask_for_a_verified_passkey_of_this_site(): void
    {
        $create = $this->rp()->creationOptions('c', 'handle', 'lena', 'Lena', ['abc']);
        $this->assertSame(['id' => self::RP, 'name' => 'Example'], $create['rp']);
        $this->assertSame('required', $create['authenticatorSelection']['userVerification']);
        $this->assertSame('required', $create['authenticatorSelection']['residentKey']);
        $this->assertSame('none', $create['attestation']);
        $this->assertSame([CoseKey::ES256, CoseKey::EDDSA, CoseKey::RS256], array_column($create['pubKeyCredParams'], 'alg'));
        $this->assertSame([['type' => 'public-key', 'id' => 'abc']], $create['excludeCredentials']);

        $get = $this->rp()->requestOptions('c');
        $this->assertSame(self::RP, $get['rpId']);
        $this->assertSame('required', $get['userVerification']);
        $this->assertSame([], $get['allowCredentials'], 'Any passkey of this site (discoverable).');
    }
}
