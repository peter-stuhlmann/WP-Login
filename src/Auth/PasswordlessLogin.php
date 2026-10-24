<?php

declare(strict_types=1);

namespace Portier\Auth;

use WP_User;

/**
 * Whether an account may log in without its password: by login link, passkey or social login.
 *
 * Those paths do not run core's `authenticate` filters, which is where core refuses accounts
 * marked as spam on a network. That check is repeated here; plugins that suspend accounts can
 * refuse more with the filter.
 */
final class PasswordlessLogin
{
    /**
     * @param string $method "magic_link", "passkey" or "social:<provider>".
     */
    public static function allowed(WP_User $user, string $method): bool
    {
        $allowed = ! (is_multisite() && is_user_spammy($user));
        /**
         * Filters whether an account may log in without its password.
         *
         * @param bool    $allowed
         * @param WP_User $user
         * @param string  $method "magic_link", "passkey" or "social:<provider>".
         */
        return (bool) apply_filters('portier_passwordless_login_allowed', $allowed, $user, $method);
    }
}
