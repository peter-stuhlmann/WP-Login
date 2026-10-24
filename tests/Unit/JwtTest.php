<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\OAuth\Jwt;
use Portier\OAuth\OAuthClient;
use Portier\OAuth\OAuthException;

/**
 * @covers \Portier\OAuth\Jwt
 * @covers \Portier\OAuth\OAuthClient::challenge
 * @covers \Portier\OAuth\OAuthClient::verifier
 */
final class JwtTest extends TestCase
{
    private static function key(string $file = 'idp-key.pem'): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private((string) file_get_contents(dirname(__DIR__) . '/Support/fixtures/' . $file));
        self::assertNotFalse($key);
        return $key;
    }

    /**
     * @return array<string, string>
     */
    private static function jwk(\OpenSSLAsymmetricKey $key): array
    {
        $details = (array) openssl_pkey_get_details($key);
        return ['kty' => 'RSA', 'n' => Jwt::base64UrlEncode($details['rsa']['n']), 'e' => Jwt::base64UrlEncode($details['rsa']['e'])];
    }

    public function test_pkce_challenge_matches_rfc_7636_appendix_b(): void
    {
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', OAuthClient::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    public function test_verifiers_are_long_random_and_url_safe(): void
    {
        $verifier = OAuthClient::verifier();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $verifier);
        $this->assertNotSame($verifier, OAuthClient::verifier());
    }

    public function test_base64url_round_trip(): void
    {
        $bytes = "\xfb\xff\xfe" . random_bytes(31);
        $encoded = Jwt::base64UrlEncode($bytes);
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $encoded);
        $this->assertSame($bytes, Jwt::base64UrlDecode($encoded));
        $this->assertSame('', Jwt::base64UrlDecode('***'));
    }

    public function test_rs256_signatures_verify_with_the_matching_jwk_only(): void
    {
        $input = Jwt::base64UrlEncode('{"alg":"RS256"}') . '.' . Jwt::base64UrlEncode('{"sub":"1"}');
        openssl_sign($input, $signature, self::key(), OPENSSL_ALGO_SHA256);

        $this->assertTrue(Jwt::verifyRs256($input, (string) $signature, self::jwk(self::key())));
        $this->assertFalse(Jwt::verifyRs256($input . 'x', (string) $signature, self::jwk(self::key())), 'Changed content.');
        $this->assertFalse(Jwt::verifyRs256($input, (string) $signature, self::jwk(self::key('idp-key-other.pem'))), 'Other key.');
        $this->assertFalse(Jwt::verifyRs256($input, '', self::jwk(self::key())), 'Missing signature.');
    }

    public function test_the_pem_is_a_valid_public_key(): void
    {
        $pem = Jwt::rsaJwkToPem(self::jwk(self::key()));
        $this->assertNotNull($pem);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
        $this->assertSame(2048, $details['bits']);
    }

    public function test_weak_or_foreign_keys_are_rejected(): void
    {
        $this->assertNull(Jwt::rsaJwkToPem(['kty' => 'RSA', 'n' => Jwt::base64UrlEncode(random_bytes(128)), 'e' => 'AQAB']), '1024 bit');
        $this->assertNull(Jwt::rsaJwkToPem(['kty' => 'EC', 'x' => 'a', 'y' => 'b']));
        $this->assertNull(Jwt::rsaJwkToPem(['kty' => 'RSA']));
    }

    /**
     * @dataProvider malformed
     */
    public function test_malformed_tokens_are_rejected(string $token): void
    {
        $this->expectException(OAuthException::class);
        Jwt::decode($token);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformed(): array
    {
        return [
            'two parts' => ['a.b'],
            'not json' => [Jwt::base64UrlEncode('nope') . '.' . Jwt::base64UrlEncode('{}') . '.sig'],
            'empty' => [''],
        ];
    }
}
