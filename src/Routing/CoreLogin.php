<?php

declare(strict_types=1);

namespace Portier\Routing;

/**
 * Knowledge about core's wp-login.php: which actions Portier renders itself and which ones
 * must keep running in core.
 */
final class CoreLogin
{
    /** Actions with their own Portier screen (aliases map to the canonical name). */
    public const HANDLED_ACTIONS = [
        'login' => 'login',
        'lostpassword' => 'lostpassword',
        'retrievepassword' => 'lostpassword',
        'rp' => 'rp',
        'resetpass' => 'rp',
        'register' => 'register',
        'checkemail' => 'checkemail',
        // Portier-only screens (two-factor authentication, magic link).
        '2fa' => '2fa',
        'recovery' => 'recovery',
        '2fa-setup' => '2fa-setup',
        'recovery-codes' => 'recovery-codes',
        'magiclink' => 'magiclink',
        'magic' => 'magic',
        // Social login (the provider callback has its own path, see LoginRoute::callbackUrl()).
        'oauth' => 'oauth',
        // Passkey login (screen and its JSON steps).
        'passkey' => 'passkey',
    ];

    /**
     * Actions that stay in core because they are not login screens: logging out, password
     * protected posts, privacy requests, the admin e-mail check and recovery mode.
     */
    public const PASSTHROUGH_ACTIONS = ['logout', 'postpass', 'confirmaction', 'confirm_admin_email', 'entered_recovery_mode'];

    /**
     * Canonical Portier action for a request, or null if core must handle it.
     *
     * @param array<string, mixed> $query
     */
    public static function portierAction(array $query): ?string
    {
        if (isset($query['interim-login'])) {
            return null; // The session-expired modal in wp-admin relies on core behaviour.
        }
        $action = isset($query['action']) && is_string($query['action']) ? $query['action'] : 'login';
        if (in_array($action, self::PASSTHROUGH_ACTIONS, true)) {
            return null;
        }
        // Unknown actions behave like core: they show the login screen.
        return self::HANDLED_ACTIONS[$action] ?? 'login';
    }

    /**
     * The real wp-login.php URL, built without the site_url filter (which we rewrite).
     *
     * @param array<string, scalar> $args
     */
    public static function url(array $args = []): string
    {
        $base = set_url_scheme(rtrim((string) get_option('siteurl'), '/') . '/wp-login.php', 'login');
        return $args === [] ? $base : add_query_arg(array_map('rawurlencode', array_map('strval', $args)), $base);
    }
}
