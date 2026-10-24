<?php

declare(strict_types=1);

namespace Portier\TwoFactor;

/**
 * Time-based one-time passwords (RFC 6238 / RFC 4226): HMAC-SHA1, 6 digits, 30-second steps –
 * the defaults every authenticator app understands.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;

    /** Accepted clock drift: one step before and after (±30 s). */
    public const WINDOW = 1;

    /**
     * New random secret (160 bit, as recommended by RFC 4226), Base32 encoded.
     */
    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /**
     * Code for one time step.
     *
     * @param string $secret Base32 secret.
     */
    public static function code(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), Base32::decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Checks a code and returns the matching time step, or null.
     *
     * $lastStep is the step of the last accepted code: a code may only be used once, so steps
     * at or before it are rejected (replay protection).
     */
    public static function verify(string $secret, string $code, int $time, ?int $lastStep = null): ?int
    {
        $code = (string) preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $current = self::step($time);
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /**
     * otpauth:// URI for the QR code (Key Uri Format used by Google Authenticator & co.).
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }
}
