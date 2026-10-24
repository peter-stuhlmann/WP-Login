<?php

declare(strict_types=1);

namespace Portier\Security;

/**
 * Authenticated encryption for secrets at rest (TOTP secrets), using libsodium's secretbox
 * (XSalsa20-Poly1305), which ships with PHP since 7.2.
 *
 * Key: the constant PORTIER_ENCRYPTION_KEY if defined (recommended, so a database leak alone
 * reveals nothing), otherwise derived from the WordPress salts in wp-config.php.
 * Note: changing the key or the salts makes stored secrets unreadable – affected users then
 * have to set up 2FA again (or an admin resets it).
 */
final class Crypto
{
    private const PREFIX = 'pc1:'; // Versioned format, so the scheme can evolve.

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(esc_html('Encryption key must be 32 bytes.'));
        }
    }

    public static function fromWordPress(): self
    {
        $material = defined('PORTIER_ENCRYPTION_KEY') && is_string(PORTIER_ENCRYPTION_KEY) && PORTIER_ENCRYPTION_KEY !== ''
            ? PORTIER_ENCRYPTION_KEY
            : wp_salt('auth') . wp_salt('secure_auth');
        return new self(hash('sha256', 'portier-crypto|' . $material, true));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding of ciphertext.
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /**
     * @return string|null Null when the value was tampered with or the key changed.
     */
    public function decrypt(string $value): ?string
    {
        if (! str_starts_with($value, self::PREFIX)) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transport encoding of ciphertext.
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        return $plain === false ? null : $plain;
    }

    /**
     * Separate, purpose-bound key for keyed hashes (recovery codes, device tokens).
     */
    public function hashKey(string $purpose): string
    {
        return hash_hmac('sha256', 'portier-hash|' . $purpose, $this->key);
    }
}
