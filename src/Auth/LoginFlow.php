<?php

declare(strict_types=1);

namespace Portier\Auth;

use Portier\TwoFactor\TwoFactorService;
use WP_User;

/**
 * What happens after the first factor (password or magic link): either the session starts right
 * away, or a pending login is created and the user continues with 2FA verification or setup.
 */
final class LoginFlow
{
    public const NEXT_VERIFY = '2fa';
    public const NEXT_SETUP = '2fa-setup';

    /** Method of the login being completed, for the `wp_login` listeners (audit log). */
    private static string $method = '';

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly PendingLogin $pending,
    ) {
    }

    /**
     * @param string $deviceToken Trusted-device cookie value ('' if none).
     * @param string $method      First factor: "password", "magic_link" or "social:<provider>".
     */
    public function afterFirstFactor(WP_User $user, bool $remember, string $redirect, string $deviceToken = '', string $method = 'password'): AuthResult
    {
        return match ($this->twoFactor->requirement($user, $deviceToken)) {
            TwoFactorService::VERIFY => AuthResult::success(null, [
                'pending' => $this->pending->create($user->ID, $remember, $redirect, PendingLogin::STAGE_VERIFY, $method),
                'next' => self::NEXT_VERIFY,
            ]),
            TwoFactorService::SETUP => AuthResult::success(null, [
                'pending' => $this->pending->create($user->ID, $remember, $redirect, PendingLogin::STAGE_SETUP, $method),
                'next' => self::NEXT_SETUP,
            ]),
            default => $this->complete($user, $remember, $redirect, $method),
        };
    }

    /**
     * Starts the session – the same steps wp_signon() performs after authentication, including
     * the `wp_login` action other plugins rely on.
     *
     * @param string $method How the person logged in, e.g. "password+totp" or "passkey".
     */
    public function complete(WP_User $user, bool $remember, string $redirect, string $method = 'password'): AuthResult
    {
        self::$method = $method;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, see wp_signon().
        $secure = (bool) apply_filters('secure_signon_cookie', is_ssl(), ['user_login' => $user->user_login, 'remember' => $remember]);
        wp_set_auth_cookie($user->ID, $remember, $secure);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action, fired for compatibility.
        do_action('wp_login', $user->user_login, $user);
        wp_set_current_user($user->ID);
        return AuthResult::success(RedirectResolver::forUser($redirect, $user), ['user' => $user]);
    }

    /**
     * Method of the login that is just being completed ('' outside Portier's login flow) – read
     * once by the audit log when `wp_login` fires.
     */
    public static function takeMethod(): string
    {
        $method = self::$method;
        self::$method = '';
        return $method;
    }

    public function pending(): PendingLogin
    {
        return $this->pending;
    }

    public function twoFactor(): TwoFactorService
    {
        return $this->twoFactor;
    }
}
