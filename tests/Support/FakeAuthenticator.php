<?php

declare(strict_types=1);

namespace Portier\Tests\Support;

use Portier\OAuth\Jwt;
use Portier\Passkeys\AuthenticatorData;
use Portier\Passkeys\CoseKey;

/**
 * A passkey authenticator for tests: produces real WebAuthn responses (client data,
 * authenticator data, attestation object, signatures) with fixed test keys.
 *
 * ES256 uses tests/Support/fixtures/passkey-p256.pem, RS256 the IdP test key, EdDSA a sodium
 * key pair. Everything a browser would send is returned base64url encoded.
 */
final class FakeAuthenticator
{
    public string $credentialId;
    public int $counter = 0;
    /** @var \OpenSSLAsymmetricKey|string */
    private $privateKey;
    private string $edPublic = '';

    public function __construct(public readonly int $algorithm = CoseKey::ES256, ?string $credentialId = null)
    {
        $this->credentialId = $credentialId ?? random_bytes(16);
        if ($algorithm === CoseKey::EDDSA) {
            $pair = sodium_crypto_sign_keypair();
            $this->privateKey = sodium_crypto_sign_secretkey($pair);
            $this->edPublic = sodium_crypto_sign_publickey($pair);
            return;
        }
        $file = $algorithm === CoseKey::RS256 ? 'idp-key.pem' : 'passkey-p256.pem';
        $key = openssl_pkey_get_private((string) file_get_contents(__DIR__ . '/fixtures/' . $file));
        if ($key === false) {
            throw new \RuntimeException('Test key missing.');
        }
        $this->privateKey = $key;
    }

    public function id(): string
    {
        return Jwt::base64UrlEncode($this->credentialId);
    }

    /**
     * Response to navigator.credentials.create().
     *
     * @return array{id: string, type: string, response: array{clientDataJSON: string, attestationObject: string}}
     */
    public function register(string $challenge, string $origin, string $rpId, int $flags = AuthenticatorData::USER_PRESENT | AuthenticatorData::USER_VERIFIED): array
    {
        $authData = hash('sha256', $rpId, true)
            . chr($flags | AuthenticatorData::ATTESTED)
            . pack('N', $this->counter)
            . str_repeat("\0", 16)
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . self::cbor($this->coseKey());
        $attestation = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);
        return [
            'id' => $this->id(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Jwt::base64UrlEncode(self::clientData('webauthn.create', $challenge, $origin)),
                'attestationObject' => Jwt::base64UrlEncode($attestation),
            ],
        ];
    }

    /**
     * Response to navigator.credentials.get().
     *
     * @return array{id: string, type: string, response: array{clientDataJSON: string, authenticatorData: string, signature: string, userHandle: string}}
     */
    public function login(
        string $challenge,
        string $origin,
        string $rpId,
        string $userHandle,
        int $flags = AuthenticatorData::USER_PRESENT | AuthenticatorData::USER_VERIFIED,
        ?string $type = null
    ): array {
        $clientData = self::clientData($type ?? 'webauthn.get', $challenge, $origin);
        $authData = hash('sha256', $rpId, true) . chr($flags) . pack('N', $this->counter);
        return [
            'id' => $this->id(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Jwt::base64UrlEncode($clientData),
                'authenticatorData' => Jwt::base64UrlEncode($authData),
                'signature' => Jwt::base64UrlEncode($this->sign($authData . hash('sha256', $clientData, true))),
                'userHandle' => $userHandle,
            ],
        ];
    }

    public function sign(string $data): string
    {
        if ($this->algorithm === CoseKey::EDDSA) {
            return sodium_crypto_sign_detached($data, (string) $this->privateKey);
        }
        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return (string) $signature;
    }

    /**
     * @return array<int, int|CborBytes>
     */
    public function coseKey(): array
    {
        if ($this->algorithm === CoseKey::EDDSA) {
            return [1 => 1, 3 => CoseKey::EDDSA, -1 => 6, -2 => new CborBytes($this->edPublic)];
        }
        $details = openssl_pkey_get_details($this->privateKey);
        if ($this->algorithm === CoseKey::RS256) {
            return [1 => 3, 3 => CoseKey::RS256, -1 => new CborBytes($details['rsa']['n']), -2 => new CborBytes($details['rsa']['e'])];
        }
        return [1 => 2, 3 => CoseKey::ES256, -1 => 1, -2 => new CborBytes($details['ec']['x']), -3 => new CborBytes($details['ec']['y'])];
    }

    public static function clientData(string $type, string $challenge, string $origin, bool $crossOrigin = false): string
    {
        return (string) wp_json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => $crossOrigin]);
    }

    /**
     * Minimal CBOR encoder (the counterpart of Portier\Passkeys\Cbor for test data).
     */
    public static function cbor(mixed $value): string
    {
        if ($value instanceof CborBytes) {
            return self::head(2, strlen($value->bytes)) . $value->bytes;
        }
        if (is_int($value)) {
            return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
        }
        if (is_string($value)) {
            return self::head(3, strlen($value)) . $value;
        }
        if (is_bool($value)) {
            return chr($value ? 0xf5 : 0xf4);
        }
        if ($value === null) {
            return chr(0xf6);
        }
        if (is_array($value) && array_is_list($value) && $value !== []) {
            return self::head(4, count($value)) . implode('', array_map([self::class, 'cbor'], $value));
        }
        $out = self::head(5, count((array) $value));
        foreach ((array) $value as $key => $item) {
            $out .= self::cbor($key) . self::cbor($item);
        }
        return $out;
    }

    private static function head(int $major, int $length): string
    {
        $type = $major << 5;
        if ($length < 24) {
            return chr($type | $length);
        }
        if ($length < 0x100) {
            return chr($type | 24) . chr($length);
        }
        if ($length < 0x10000) {
            return chr($type | 25) . pack('n', $length);
        }
        return chr($type | 26) . pack('N', $length);
    }
}
