<?php

declare(strict_types=1);

namespace Portier\Auth;

use Portier\Http\AfterResponse;
use Portier\Security\LoginThrottle;
use Portier\Security\RateLimiter;
use WP_Error;
use WP_Session_Tokens;
use WP_User;

/**
 * "Forgot password" and "set new password".
 *
 * Requesting a link always answers the same way, whether or not the account exists, so the
 * form cannot be used to find out who is registered. Reset links are created and checked by
 * core (hashed key, 24h lifetime by default); this service only adds rate limiting, a minimum
 * password length and ends all sessions after a successful reset.
 */
final class PasswordResetService
{
    /** Errors from retrieve_password() that must not be shown to the visitor. */
    private const NEUTRAL_ERRORS = ['invalid_email', 'invalidcombo', 'retrieve_password_email_failure', 'no_password_reset'];

    public function __construct(
        private readonly RateLimiter $ipLimiter,
        private readonly RateLimiter $accountLimiter,
        private readonly string $clientIp,
        private readonly int $minLength,
    ) {
    }

    public function request(string $login): AuthResult
    {
        $login = trim($login);
        if ($login === '') {
            return AuthResult::failure(['user_login' => __('Please enter your email address or username.', 'portier')]);
        }

        $ipKey = 'reset-ip|' . $this->clientIp;
        $locked = $this->ipLimiter->lockedFor($ipKey);
        if ($locked > 0) {
            return AuthResult::formError(LoginThrottle::lockedMessage($locked), ['login' => $login]);
        }
        // Every request counts against the IP, successful or not: this form sends e-mails.
        $this->ipLimiter->hit($ipKey);

        // Per account we silently stop sending (to avoid mail bombing) but answer as usual. Login
        // name and e-mail address of the same account share one counter.
        $user = is_email($login) ? get_user_by('email', $login) : get_user_by('login', $login);
        $accountKey = $user instanceof WP_User ? 'reset-user|' . $user->ID : 'reset-login|' . strtolower($login);
        if ($this->accountLimiter->lockedFor($accountKey) > 0) {
            return AuthResult::success();
        }
        $this->accountLimiter->hit($accountKey);

        // Core only reports errors the visitor must not see – unless a plugin checks the form
        // (captcha). Without one, the e-mail goes out after the response, whose timing then does
        // not tell whether the account exists.
        if (! has_action('lostpassword_post') && ! has_filter('lostpassword_errors')) {
            AfterResponse::queue(static function () use ($login): void {
                retrieve_password($login);
            });
            return AuthResult::success();
        }
        $result = retrieve_password($login);
        if ($result instanceof WP_Error) {
            $visible = array_diff($result->get_error_codes(), self::NEUTRAL_ERRORS);
            if ($visible !== []) {
                // E.g. a captcha plugin hooked into `lostpassword_post`.
                $messages = array_map(fn ($code) => wp_strip_all_tags((string) $result->get_error_message($code)), $visible);
                return AuthResult::formError(implode(' ', $messages), ['login' => $login]);
            }
        }
        return AuthResult::success();
    }

    /**
     * @return WP_User|WP_Error
     */
    public function checkKey(string $key, string $login)
    {
        if ($key === '' || $login === '') {
            return new WP_Error('invalid_key');
        }
        return check_password_reset_key($key, $login);
    }

    public function reset(WP_User $user, string $password, string $confirmation): AuthResult
    {
        $errors = new WP_Error();
        if ($password === '') {
            $errors->add('pass1', __('Please enter a new password.', 'portier'));
        } elseif (mb_strlen($password) < $this->minLength) {
            $errors->add(
                'pass1',
                sprintf(
                    /* translators: %d: minimum number of characters */
                    _n('Please use at least %d character.', 'Please use at least %d characters.', $this->minLength, 'portier'),
                    $this->minLength
                )
            );
        }
        if ($password !== $confirmation) {
            $errors->add('pass2', __('The passwords do not match.', 'portier'));
        }

        /** This action is documented in wp-login.php – lets plugins enforce their own rules. */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired for compatibility.
        do_action('validate_password_reset', $errors, $user);

        if ($errors->has_errors()) {
            $mapped = [];
            foreach ($errors->get_error_codes() as $code) {
                $field = in_array($code, ['pass1', 'pass2'], true) ? (string) $code : AuthResult::FORM;
                $mapped[$field] = wp_strip_all_tags((string) $errors->get_error_message($code));
            }
            return AuthResult::failure($mapped);
        }

        reset_password($user, $password);
        // Somebody may have reset the password because the account was compromised.
        WP_Session_Tokens::get_instance($user->ID)->destroy_all();
        return AuthResult::success();
    }

    public function minLength(): int
    {
        return $this->minLength;
    }
}
