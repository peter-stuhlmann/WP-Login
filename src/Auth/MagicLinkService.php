<?php

declare(strict_types=1);

namespace Portier\Auth;

use Portier\Http\AfterResponse;
use Portier\Routing\LoginRoute;
use Portier\Security\LoginThrottle;
use Portier\Security\RateLimiter;
use WP_Error;
use WP_User;

/**
 * Passwordless login via a one-time link sent by e-mail.
 *
 * Token design (selector/verifier): the link contains a public selector to find the account and
 * a secret verifier; only a keyed hash of the verifier is stored. Each link works once, expires
 * after a few minutes, and only the newest link of an account is valid.
 *
 * The link does not log in by itself: it opens a confirmation page with a button (POST).
 * E-mail security scanners that open every link therefore cannot trigger a login.
 */
final class MagicLinkService
{
    private const META = 'portier_magic';
    private const META_SELECTOR = 'portier_magic_selector';

    public function __construct(
        private readonly LoginRoute $route,
        private readonly RateLimiter $ipLimiter,
        private readonly RateLimiter $accountLimiter,
        private readonly string $clientIp,
        private readonly int $lifetimeMinutes,
        private readonly string $hashKey,
    ) {
    }

    /**
     * Sends a link if the account exists. The answer is the same either way.
     */
    public function request(string $login, string $redirect): AuthResult
    {
        $login = trim($login);
        if ($login === '') {
            return AuthResult::failure(['user_login' => __('Please enter your email address or username.', 'portier')]);
        }

        $ipKey = 'magic-ip|' . $this->clientIp;
        $locked = $this->ipLimiter->lockedFor($ipKey);
        if ($locked > 0) {
            return AuthResult::formError(LoginThrottle::lockedMessage($locked), ['login' => $login]);
        }
        $this->ipLimiter->hit($ipKey);

        $user = is_email($login) ? get_user_by('email', $login) : get_user_by('login', $login);
        if (! $user instanceof WP_User || ! PasswordlessLogin::allowed($user, 'magic_link')) {
            return AuthResult::success();
        }
        $accountKey = 'magic-account|' . $user->ID;
        if ($this->accountLimiter->lockedFor($accountKey) > 0) {
            return AuthResult::success(); // Silently: no mail bombing, no hint.
        }
        $this->accountLimiter->hit($accountKey);

        // After the response: its timing must not tell whether the account exists.
        AfterResponse::queue(fn () => $this->send($user, $this->issue($user, $redirect)));
        return AuthResult::success();
    }

    /**
     * Creates a new token for the user (replacing any older one) and returns it.
     */
    public function issue(WP_User $user, string $redirect = ''): string
    {
        $selector = bin2hex(random_bytes(9));
        $verifier = bin2hex(random_bytes(24));
        update_user_meta($user->ID, self::META_SELECTOR, $selector);
        update_user_meta($user->ID, self::META, [
            'hash' => hash_hmac('sha256', $verifier, $this->hashKey),
            'expires' => time() + $this->lifetimeMinutes * MINUTE_IN_SECONDS,
            'redirect' => $redirect,
        ]);
        return $selector . '.' . $verifier;
    }

    /**
     * Validates a token without using it up (for the confirmation page).
     *
     * @return WP_User|WP_Error
     */
    public function check(string $token)
    {
        [$selector, $verifier] = array_pad(explode('.', $token, 2), 2, '');
        if (! preg_match('/^[a-f0-9]{18}$/', $selector) || ! preg_match('/^[a-f0-9]{48}$/', $verifier)) {
            return new WP_Error('invalid_magic_link');
        }
        // phpcs:ignore WordPress.DB.SlowDBQuery -- Lookup of a single row by an indexed meta key.
        $users = get_users(['meta_key' => self::META_SELECTOR, 'meta_value' => $selector, 'number' => 1, 'blog_id' => 0]);
        $user = $users[0] ?? null;
        if (! $user instanceof WP_User) {
            return new WP_Error('invalid_magic_link');
        }
        $data = get_user_meta($user->ID, self::META, true);
        if (! is_array($data) || ! hash_equals((string) ($data['hash'] ?? ''), hash_hmac('sha256', $verifier, $this->hashKey))) {
            return new WP_Error('invalid_magic_link');
        }
        if ((int) ($data['expires'] ?? 0) < time()) {
            return new WP_Error('expired_magic_link');
        }
        if (! PasswordlessLogin::allowed($user, 'magic_link')) {
            return new WP_Error('invalid_magic_link');
        }
        return $user;
    }

    /**
     * Validates and invalidates the token (single use). Returns the user and the redirect stored
     * with the link.
     *
     * @return array{user: WP_User, redirect: string}|WP_Error
     */
    public function consume(string $token)
    {
        $user = $this->check($token);
        if ($user instanceof WP_Error) {
            return $user;
        }
        $data = (array) get_user_meta($user->ID, self::META, true);
        // Deleting is the single use: of two parallel requests only one deletes the row.
        if (! delete_user_meta($user->ID, self::META)) {
            return new WP_Error('invalid_magic_link');
        }
        delete_user_meta($user->ID, self::META_SELECTOR);
        return ['user' => $user, 'redirect' => (string) ($data['redirect'] ?? '')];
    }

    private function send(WP_User $user, string $token): void
    {
        $switched = switch_to_user_locale($user->ID);
        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $url = $this->route->url('magic', ['token' => $token]);

        /* translators: %s: site name */
        $subject = sprintf(__('[%s] Your login link', 'portier'), $site);
        $paragraphs = [
            /* translators: %s: user display name */
            sprintf(__('Hi %s,', 'portier'), $user->display_name),
            /* translators: %s: site name */
            sprintf(__('here is your login link for %s:', 'portier'), $site),
            $url,
            sprintf(
                /* translators: %d: lifetime of the link in minutes */
                _n('The link works once and expires in %d minute.', 'The link works once and expires in %d minutes.', $this->lifetimeMinutes, 'portier'),
                $this->lifetimeMinutes
            ),
            __('If you did not request this link, you can ignore this email – nobody can log in without it.', 'portier'),
        ];
        wp_mail($user->user_email, $subject, implode("\n\n", $paragraphs));
        if ($switched) {
            restore_previous_locale();
        }
        /**
         * Fires after a login link was sent (audit log).
         *
         * @param WP_User $user
         */
        do_action('portier_magic_link_sent', $user);
    }
}
