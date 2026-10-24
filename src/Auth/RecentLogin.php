<?php

declare(strict_types=1);

namespace Portier\Auth;

use Portier\Routing\LoginRoute;
use WP_Session_Tokens;

/**
 * "Confirm it's you" for changes that create a new way into an account: adding a passkey,
 * connecting a social account, setting up two-factor authentication and creating recovery codes.
 *
 * They are only allowed in a session that started a few minutes ago. A stolen session cookie
 * therefore cannot be turned into a permanent way in (a passkey even survives a password reset).
 * Otherwise people log in again – with every factor they use – and come back.
 */
final class RecentLogin
{
    /** Default age of a session that still counts as "just logged in". */
    public const MAX_AGE = 15 * MINUTE_IN_SECONDS;

    public function __construct(private readonly LoginRoute $route)
    {
    }

    /**
     * Whether the current user's session started within the allowed age.
     */
    public function isRecent(): bool
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return false;
        }
        $session = WP_Session_Tokens::get_instance($userId)->get(wp_get_session_token());
        $started = is_array($session) ? (int) ($session['login'] ?? 0) : 0;
        /**
         * Filters how long after logging in sensitive account changes are allowed without
         * logging in again.
         *
         * @param int $seconds Default 15 minutes.
         */
        $maxAge = (int) apply_filters('portier_recent_login_seconds', self::MAX_AGE);
        return $started > 0 && time() - $started <= $maxAge;
    }

    /**
     * Login screen that asks to log in again and then returns to $returnTo.
     */
    public function confirmUrl(string $returnTo): string
    {
        return $this->route->url('login', ['reauth' => '1', 'redirect_to' => $returnTo]);
    }
}
