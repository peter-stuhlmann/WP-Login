<?php

declare(strict_types=1);

namespace Portier\Security;

use WP_Error;
use WP_User;

/**
 * Brute-force protection for every authentication path.
 *
 * It hooks into core authentication instead of our login form, so it equally protects
 * wp-login.php, XML-RPC and other plugins' login forms. (REST requests with application
 * passwords bypass the `authenticate` filters in core; those passwords are random and cannot be
 * guessed.) Two limiters work together:
 *
 * - per IP: few attempts, doubling lock (stops a single attacker quickly),
 * - per account: more attempts, short lock (slows down distributed attacks on one account
 *   without letting anyone lock a user out for long).
 */
final class LoginThrottle
{
    public const ERROR_CODE = 'portier_locked';

    public function __construct(
        private readonly RateLimiter $ipLimiter,
        private readonly RateLimiter $accountLimiter,
        private readonly string $clientIp,
    ) {
    }

    public function register(): void
    {
        // Priority 99: after core has validated the password, so a correct password cannot
        // override the lock (filters earlier in the chain would be overwritten by core).
        add_filter('authenticate', [$this, 'enforce'], 99, 2);
        add_action('wp_login_failed', [$this, 'recordFailure'], 10, 2);
        add_action('wp_login', [$this, 'recordSuccess'], 10, 1);
    }

    /**
     * @param WP_User|WP_Error|null $user
     * @return WP_User|WP_Error|null
     */
    public function enforce($user, string $username = '')
    {
        if ($username === '' && ! $user instanceof WP_User) {
            return $user; // Nothing submitted (e.g. plain page load) – nothing to throttle.
        }
        $seconds = $this->lockedFor($username);
        if ($seconds > 0) {
            return new WP_Error(self::ERROR_CODE, self::lockedMessage($seconds), ['retry_after' => $seconds]);
        }
        return $user;
    }

    public function lockedFor(string $username): int
    {
        return max(
            $this->ipLimiter->lockedFor($this->ipKey()),
            $username === '' ? 0 : $this->accountLimiter->lockedFor($this->accountKey($username))
        );
    }

    public function recordFailure(string $username, ?WP_Error $error = null): void
    {
        if ($error instanceof WP_Error && $error->get_error_code() === self::ERROR_CODE) {
            return; // Already rejected because of the lock; do not count twice.
        }
        $lock = $this->ipLimiter->hit($this->ipKey());
        if ($username !== '') {
            $lock = max($lock, $this->accountLimiter->hit($this->accountKey($username)));
        }
        if ($lock > 0) {
            /**
             * Fires when too many failed logins lead to a pause (audit log).
             *
             * @param string $username Login name of the last attempt.
             * @param int    $seconds  Length of the pause.
             */
            do_action('portier_login_locked', $username, $lock);
        }
    }

    /**
     * A successful login clears its account's counter – not the IP counter: otherwise an
     * attacker could reset it with a login to an account of their own between guesses.
     */
    public function recordSuccess(string $login): void
    {
        $this->accountLimiter->clear($this->accountKey($login));
    }

    public static function lockedMessage(int $seconds): string
    {
        return sprintf(
            /* translators: %s: human readable duration, e.g. "5 mins" */
            __('Too many failed attempts. For your security, please wait %s and try again.', 'portier'),
            human_time_diff(time(), time() + max(60, $seconds))
        );
    }

    private function ipKey(): string
    {
        return 'ip|' . $this->clientIp;
    }

    /**
     * Logins and e-mail addresses of the same account share one counter.
     */
    private function accountKey(string $username): string
    {
        $username = strtolower(trim($username));
        if (is_email($username)) {
            $user = get_user_by('email', $username);
            if ($user instanceof WP_User) {
                $username = strtolower($user->user_login);
            }
        }
        return 'account|' . $username;
    }
}
