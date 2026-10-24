<?php

declare(strict_types=1);

namespace Portier\Auth;

/**
 * Derives a unique username from an e-mail address, so registration only asks for what
 * people actually know: their e-mail address. The username is never shown on our screens.
 */
final class UsernameGenerator
{
    /** Predictable names attackers try first – never generate them. */
    private const AVOID = ['admin', 'administrator', 'root', 'test', 'user', 'webmaster', 'info'];

    /**
     * @param callable(string): bool $exists Returns true if the username is taken.
     */
    public static function fromEmail(string $email, callable $exists): string
    {
        $local = strtolower((string) strstr($email, '@', true));
        $base = trim((string) preg_replace('/[^a-z0-9._-]+/', '', $local), '._-');
        if (strlen($base) < 3 || in_array($base, self::AVOID, true)) {
            $base = 'member' . ($base !== '' ? '-' . $base : '');
        }
        $base = substr($base, 0, 50);

        $candidate = $base;
        for ($i = 2; $exists($candidate); $i++) {
            if ($i > 50) {
                // Pathological case: fall back to a random suffix instead of looping forever.
                $candidate = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
            $candidate = $base . $i;
        }
        return $candidate;
    }
}
