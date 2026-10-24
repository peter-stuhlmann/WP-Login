<?php

declare(strict_types=1);

namespace Portier\Auth;

use Portier\Security\LoginThrottle;
use Portier\TwoFactor\TwoFactorGate;
use WP_Error;

/**
 * Username/e-mail + password login.
 *
 * The password is checked with the core pipeline (`wp_authenticate` action, `authenticate`
 * filters, `wp_login_failed`), so security plugins, SSO bridges and our {@see LoginThrottle}
 * all apply. Unlike wp_signon() the session is not started here: {@see LoginFlow} decides
 * whether a second factor is needed first.
 */
final class LoginService
{
    /** Core error codes that would reveal whether an account exists. */
    private const CREDENTIAL_ERRORS = ['invalid_username', 'invalid_email', 'incorrect_password', 'empty_username', 'empty_password'];

    public function __construct(private readonly LoginFlow $flow)
    {
    }

    /**
     * @param string $deviceToken Trusted-device cookie value ('' if none).
     */
    public function attempt(string $login, string $password, bool $remember, string $requestedRedirect, string $deviceToken = ''): AuthResult
    {
        $login = trim($login);
        $errors = [];
        if ($login === '') {
            $errors['log'] = __('Please enter your email address or username.', 'portier');
        }
        if ($password === '') {
            $errors['pwd'] = __('Please enter your password.', 'portier');
        }
        if ($errors !== []) {
            return AuthResult::failure($errors, ['login' => $login]);
        }

        /** This action is documented in wp-includes/user.php (wp_signon). */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core action, fired for compatibility.
        do_action_ref_array('wp_authenticate', [&$login, &$password]);
        $user = TwoFactorGate::firstFactor(static fn () => wp_authenticate($login, $password));

        if ($user instanceof WP_Error) {
            return AuthResult::failure($this->mapErrors($user), ['login' => $login]);
        }

        return $this->flow->afterFirstFactor($user, $remember, $requestedRedirect, $deviceToken);
    }

    /**
     * Turns core errors into neutral, translated messages.
     *
     * @return array<string, string>
     */
    private function mapErrors(WP_Error $error): array
    {
        $code = (string) $error->get_error_code();

        if ($code === LoginThrottle::ERROR_CODE) {
            return [AuthResult::FORM => $error->get_error_message()];
        }

        if (in_array($code, self::CREDENTIAL_ERRORS, true)) {
            // Same wording for "unknown user" and "wrong password" – no account enumeration.
            return [AuthResult::FORM => __('The email address/username or password is incorrect. Please check your input and try again.', 'portier')];
        }

        // Errors added by other plugins (captcha, pending activation, …): show their text, but
        // without markup, because it may contain links to wp-login.php.
        $message = trim(wp_strip_all_tags($error->get_error_message()));
        $message = (string) preg_replace('/^(Error|Fehler):\s*/i', '', $message);
        return [AuthResult::FORM => $message !== '' ? $message : __('Login failed. Please try again.', 'portier')];
    }
}
