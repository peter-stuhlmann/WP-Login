<?php

declare(strict_types=1);

namespace Portier\Passkeys;

use Portier\OAuth\Jwt;

/**
 * A credential public key in COSE format (RFC 9052/9053), as authenticators deliver it.
 *
 * Accepted: ES256 (ECDSA P-256 – almost all passkeys), EdDSA (Ed25519) and RS256 (RSA, at
 * least 2048 bits – Windows Hello). Keys are stored as PEM (EC, RSA) or raw bytes (Ed25519).
 */
final class CoseKey
{
    public const ES256 = -7;
    public const EDDSA = -8;
    public const RS256 = -257;

    /** Algorithms offered to the browser, in order of preference. */
    public const ALGORITHMS = [self::ES256, self::EDDSA, self::RS256];

    // COSE labels.
    private const KTY = 1;
    private const ALG = 3;
    private const CRV = -1;
    private const X = -2;
    private const Y = -3;
    private const RSA_N = -1;
    private const RSA_E = -2;

    private function __construct(
        public readonly int $algorithm,
        private readonly string $key,
    ) {
    }

    /**
     * @param mixed $map Decoded COSE_Key map.
     * @throws PasskeyException
     */
    public static function fromCose(mixed $map): self
    {
        if (! is_array($map) || ! is_int($map[self::KTY] ?? null) || ! is_int($map[self::ALG] ?? null)) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Invalid COSE key.'));
        }
        $algorithm = $map[self::ALG];

        if ($algorithm === self::ES256 && $map[self::KTY] === 2 && ($map[self::CRV] ?? null) === 1) {
            $x = $map[self::X] ?? null;
            $y = $map[self::Y] ?? null;
            if (! is_string($x) || ! is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Invalid P-256 coordinates.'));
            }
            // SubjectPublicKeyInfo: id-ecPublicKey with prime256v1, uncompressed point.
            $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return self::checked(new self($algorithm, self::pem($spki)));
        }
        if ($algorithm === self::EDDSA && $map[self::KTY] === 1 && ($map[self::CRV] ?? null) === 6) {
            $x = $map[self::X] ?? null;
            if (! is_string($x) || strlen($x) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Invalid Ed25519 key.'));
            }
            return new self($algorithm, $x);
        }
        if ($algorithm === self::RS256 && $map[self::KTY] === 3) {
            $n = $map[self::RSA_N] ?? null;
            $e = $map[self::RSA_E] ?? null;
            $pem = is_string($n) && is_string($e)
                ? Jwt::rsaJwkToPem(['kty' => 'RSA', 'n' => Jwt::base64UrlEncode($n), 'e' => Jwt::base64UrlEncode($e)])
                : null;
            if ($pem === null) {
                throw new PasskeyException(esc_html(PasskeyException::ALGORITHM), esc_html('RSA key missing or below 2048 bits.'));
            }
            return self::checked(new self($algorithm, $pem));
        }
        throw new PasskeyException(esc_html(PasskeyException::ALGORITHM), esc_html('Unsupported COSE algorithm ' . $algorithm . '.'));
    }

    /**
     * Restores a stored key.
     *
     * @param array{alg?: mixed, key?: mixed} $stored
     * @throws PasskeyException
     */
    public static function import(array $stored): self
    {
        $algorithm = is_int($stored['alg'] ?? null) ? $stored['alg'] : 0;
        $key = is_string($stored['key'] ?? null) ? $stored['key'] : '';
        if (! in_array($algorithm, self::ALGORITHMS, true) || $key === '') {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Stored key is invalid.'));
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Storage encoding.
        return new self($algorithm, $algorithm === self::EDDSA ? (string) base64_decode($key, true) : $key);
    }

    /**
     * @return array{alg: int, key: string} For storage (user meta).
     */
    public function export(): array
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storage encoding.
        return ['alg' => $this->algorithm, 'key' => $this->algorithm === self::EDDSA ? base64_encode($this->key) : $this->key];
    }

    /**
     * Checks a signature. ES256 signatures from WebAuthn are DER encoded, like OpenSSL expects.
     */
    public function verify(string $data, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }
        if ($this->algorithm === self::EDDSA) {
            return strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                && strlen($this->key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($signature, $data, $this->key);
        }
        $key = openssl_pkey_get_public($this->key);
        return $key !== false && openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * OpenSSL must accept the key (e.g. the point lies on the curve).
     *
     * @throws PasskeyException
     */
    private static function checked(self $key): self
    {
        if (openssl_pkey_get_public($key->key) === false) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Public key rejected by OpenSSL.'));
        }
        return $key;
    }

    private static function pem(string $der): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM encoding.
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
