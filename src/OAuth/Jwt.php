<?php

declare(strict_types=1);

namespace Portier\OAuth;

/**
 * Minimal JSON Web Token support for OpenID Connect ID tokens: decoding and RS256 signature
 * verification against a JSON Web Key (RFC 7515, 7517, 7518). Deliberately nothing else –
 * every other algorithm (including "none" and HMAC) is rejected.
 */
final class Jwt
{
    /**
     * Splits and decodes a compact JWT.
     *
     * @return array{header: array<string, mixed>, claims: array<string, mixed>, input: string, signature: string}
     * @throws OAuthException When the token is malformed.
     */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token is not a compact JWT.'));
        }
        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $claims = json_decode(self::base64UrlDecode($parts[1]), true);
        if (! is_array($header) || ! is_array($claims)) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token header or claims are not JSON objects.'));
        }
        return [
            'header' => $header,
            'claims' => $claims,
            'input' => $parts[0] . '.' . $parts[1],
            'signature' => self::base64UrlDecode($parts[2]),
        ];
    }

    /**
     * Verifies an RS256 signature with an RSA public JWK.
     *
     * @param array<string, mixed> $jwk
     */
    public static function verifyRs256(string $input, string $signature, array $jwk): bool
    {
        $pem = self::rsaJwkToPem($jwk);
        if ($pem === null || $signature === '') {
            return false;
        }
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }
        return openssl_verify($input, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Converts an RSA public JWK (`n`, `e`) into a PEM "PUBLIC KEY" (SubjectPublicKeyInfo).
     *
     * @param array<string, mixed> $jwk
     */
    public static function rsaJwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            return null;
        }
        $modulus = self::base64UrlDecode($jwk['n']);
        $exponent = self::base64UrlDecode($jwk['e']);
        if (strlen($modulus) < 256 || $exponent === '') {
            return null; // Below 2048 bits is not acceptable.
        }
        $rsaKey = self::der(0x30, self::derInteger($modulus) . self::derInteger($exponent));
        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) with NULL parameters.
        $algorithm = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $spki = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaKey));
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM encoding.
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function base64UrlEncode(string $data): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url (RFC 4648).
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64url (RFC 4648).
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * DER: tag, length, value.
     */
    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 0x80) {
            return chr($tag) . chr($length) . $value;
        }
        $bytes = ltrim(pack('N', $length), "\x00");
        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $value;
    }

    /**
     * DER INTEGER from unsigned big-endian bytes (a leading zero keeps it positive).
     */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }
        return self::der(0x02, $bytes);
    }
}
