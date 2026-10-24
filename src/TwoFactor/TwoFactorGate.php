<?php

declare(strict_types=1);

namespace Portier\TwoFactor;

use Portier\Routing\LoginRoute;
use WP_Error;
use WP_User;

/**
 * Enforces 2FA on every password-based login path.
 *
 * Without this, an attacker who knows a password could skip the second factor by posting to
 * wp-login.php or using XML-RPC. Portier's own screens run the first factor inside
 * {@see TwoFactorGate::firstFactor()} and ask for the second factor themselves.
 * Application passwords (XML-RPC) are not affected: they are separate, random credentials that
 * only a logged-in user can create.
 */
final class TwoFactorGate
{
    public const ERROR_CODE = 'portier_2fa_required';

    private static bool $firstFactorOnly = false;

    /** Account that just authenticated with an application password in this request. */
    private ?int $applicationPasswordUser = null;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly LoginRoute $route,
    ) {
    }

    public function register(): void
    {
        // After core (20) and the throttle (99): only a successfully authenticated user is checked.
        add_filter('authenticate', [$this, 'enforce'], 100);
        add_action('application_password_did_authenticate', [$this, 'rememberApplicationPassword']);
    }

    /**
     * Core fires this inside `authenticate` when an application password matched.
     */
    public function rememberApplicationPassword(WP_User $user): void
    {
        $this->applicationPasswordUser = $user->ID;
    }

    /**
     * Runs a first-factor check for Portier's own login flow.
     *
     * @template T
     * @param callable(): T $check
     * @return T
     */
    public static function firstFactor(callable $check)
    {
        self::$firstFactorOnly = true;
        try {
            return $check();
        } finally {
            self::$firstFactorOnly = false;
        }
    }

    /**
     * @param WP_User|WP_Error|null $user
     * @return WP_User|WP_Error|null
     */
    public function enforce($user)
    {
        if (self::$firstFactorOnly || ! $user instanceof WP_User || $user->ID === $this->applicationPasswordUser) {
            return $user;
        }
        if ($this->twoFactor->requirement($user) === TwoFactorService::NONE) {
            return $user;
        }
        return new WP_Error(
            self::ERROR_CODE,
            sprintf(
                /* translators: %s: URL of the Portier login page */
                __('This account is protected with two-factor authentication. Please log in at %s.', 'portier'),
                $this->route->url()
            )
        );
    }
}
