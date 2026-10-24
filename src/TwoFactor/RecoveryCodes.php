<?php

declare(strict_types=1);

namespace Portier\TwoFactor;

/**
 * One-time recovery codes, e.g. "K7QF-2M9X".
 *
 * Codes use an alphabet without look-alikes (no 0/O, 1/I/L) and carry 40 bits of entropy.
 * Only keyed hashes are stored; comparison is case-insensitive and ignores spaces/hyphens.
 */
final class RecoveryCodes
{
    public const COUNT = 10;
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * @return list<string> Plain codes, shown to the user exactly once.
     */
    public static function generate(int $count = self::COUNT): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4);
        }
        return array_values(array_unique($codes));
    }

    public static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function hash(string $code, string $key): string
    {
        return hash_hmac('sha256', self::normalise($code), $key);
    }

    /**
     * Finds a code among stored hashes; returns the index of the match or null.
     *
     * @param list<string> $hashes
     */
    public static function match(string $code, array $hashes, string $key): ?int
    {
        if (strlen(self::normalise($code)) !== 8) {
            return null;
        }
        $candidate = self::hash($code, $key);
        $found = null;
        foreach ($hashes as $index => $hash) {
            // Compare all entries (no early exit) to keep the timing independent of the position.
            if (hash_equals($hash, $candidate)) {
                $found = $index;
            }
        }
        return $found;
    }
}
