<?php

declare(strict_types=1);

namespace Portier\Auth;

use WP_User;

/**
 * Where to send a user after logging in – identical to core's wp-login.php logic, including
 * the `login_redirect` filter, so existing customisations keep working.
 */
final class RedirectResolver
{
    public static function forUser(string $requested, WP_User $user): string
    {
        $redirect = $requested !== ''
            ? wp_validate_redirect(wp_sanitize_redirect($requested), admin_url())
            : admin_url();

        /** This filter is documented in wp-login.php */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.
        $redirect = (string) apply_filters('login_redirect', $redirect, $requested, $user);

        // Users without access to the dashboard should not land on an empty wp-admin.
        if ($redirect === '' || $redirect === 'wp-admin/' || $redirect === admin_url()) {
            if (is_multisite() && ! get_active_blog_for_user($user->ID) && ! is_super_admin($user->ID)) {
                $redirect = user_admin_url();
            } elseif (is_multisite() && ! $user->has_cap('read')) {
                $redirect = get_dashboard_url($user->ID);
            } elseif (! $user->has_cap('edit_posts')) {
                $redirect = $user->has_cap('read') ? admin_url('profile.php') : home_url();
            }
        }

        return wp_validate_redirect($redirect, admin_url());
    }

    /**
     * Sanitises a requested redirect from user input without resolving it yet.
     */
    public static function sanitizeRequested(string $requested): string
    {
        if ($requested === '') {
            return '';
        }
        return wp_validate_redirect(wp_sanitize_redirect($requested), '');
    }
}
