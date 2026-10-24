<?php

declare(strict_types=1);

namespace Portier\Auth;

use Portier\Http\AfterResponse;
use Portier\Security\LoginThrottle;
use Portier\Security\RateLimiter;
use WP_Error;
use WP_User;

/**
 * Self-registration for single sites and multisite networks.
 *
 * Visitors only enter their e-mail address (and optionally a name). They then receive the core
 * "set your password" e-mail – which doubles as e-mail verification, because only the owner of
 * the address can finish the sign-up. On multisite the core signup/activation flow is used.
 *
 * Privacy: if the address is already registered, the answer is the same as for a new sign-up
 * and the owner gets a friendly e-mail instead. Nobody learns which addresses have an account.
 */
final class RegistrationService
{
    public function __construct(
        private readonly RateLimiter $ipLimiter,
        private readonly string $clientIp,
    ) {
    }

    /**
     * Whether visitors may register (core settings are the single source of truth).
     */
    public static function isOpen(): bool
    {
        if (is_multisite()) {
            return in_array(get_site_option('registration'), ['user', 'all'], true);
        }
        return (bool) get_option('users_can_register');
    }

    public function register(string $email, string $name, bool $consentGiven, bool $consentRequired): AuthResult
    {
        $email = trim($email);
        $name = trim(wp_strip_all_tags($name));
        $data = ['email' => $email, 'name' => $name];

        if (! self::isOpen()) {
            return AuthResult::formError(__('Registration is currently closed.', 'portier'), $data);
        }

        $errors = [];
        if ($email === '' || ! is_email($email)) {
            $errors['user_email'] = __('Please enter a valid email address, e.g. name@example.com.', 'portier');
        }
        if ($consentRequired && ! $consentGiven) {
            $errors['privacy_consent'] = __('Please agree to the privacy policy to create an account.', 'portier');
        }
        if (mb_strlen($name) > 100) {
            $errors['display_name'] = __('Please use at most 100 characters for your name.', 'portier');
        }
        if ($errors !== []) {
            return AuthResult::failure($errors, $data);
        }

        $ipKey = 'register-ip|' . $this->clientIp;
        $locked = $this->ipLimiter->lockedFor($ipKey);
        if ($locked > 0) {
            return AuthResult::formError(LoginThrottle::lockedMessage($locked), $data);
        }
        $this->ipLimiter->hit($ipKey);

        // All e-mails of this form go out after the response: its timing must not tell whether
        // the address is already registered.
        $existing = get_user_by('email', $email);
        if ($existing instanceof WP_User) {
            AfterResponse::queue(fn () => $this->notifyExistingAccount($existing));
            return AuthResult::success(null, $data);
        }

        $username = UsernameGenerator::fromEmail($email, static fn (string $login): bool => username_exists($login) !== false);

        return is_multisite()
            ? $this->signupOnNetwork($username, $email, $name, $data)
            : $this->registerOnSite($username, $email, $name, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function registerOnSite(string $username, string $email, string $name, array $data): AuthResult
    {
        // register_new_user() runs `register_post` / `registration_errors` (spam plugins) and
        // sends the "set your password" e-mail, whose link points to our reset screen.
        $userId = self::withDeferredMail('register_new_user', 'wp_send_new_user_notifications', 1, static fn () => register_new_user($username, $email));
        if ($userId instanceof WP_Error) {
            return $this->mapErrors($userId, $data);
        }
        if ($name !== '') {
            wp_update_user(['ID' => $userId, 'first_name' => $name, 'display_name' => $name, 'nickname' => $name]);
        }
        return AuthResult::success(null, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function signupOnNetwork(string $username, string $email, string $name, array $data): AuthResult
    {
        $validation = wpmu_validate_user_signup($username, $email);
        $errors = $validation['errors'];
        if ($errors instanceof WP_Error && $errors->has_errors()) {
            return $this->mapErrors($errors, $data);
        }
        self::withDeferredMail(
            'after_signup_user',
            'wpmu_signup_user_notification',
            4,
            static fn () => wpmu_signup_user($validation['user_name'], $validation['user_email'], ['first_name' => $name, 'display_name' => $name])
        );
        return AuthResult::success(null, $data);
    }

    /**
     * Runs $register while core's notification for $hook is moved behind the response.
     *
     * @template T
     * @param callable(): T $register
     * @return T
     */
    private static function withDeferredMail(string $hook, callable $notify, int $arguments, callable $register)
    {
        $priority = has_action($hook, $notify);
        if ($priority === false) {
            return $register();
        }
        $deferred = static function (mixed ...$args) use ($notify): void {
            AfterResponse::queue(static fn () => $notify(...$args));
        };
        remove_action($hook, $notify, $priority);
        add_action($hook, $deferred, $priority, $arguments);
        try {
            return $register();
        } finally {
            remove_action($hook, $deferred, $priority);
            add_action($hook, $notify, $priority, $arguments);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapErrors(WP_Error $error, array $data): AuthResult
    {
        $codes = $error->get_error_codes();
        // A race with a parallel sign-up: stay neutral, like for existing accounts.
        if (array_intersect($codes, ['email_exists']) !== [] && count($codes) === 1) {
            return AuthResult::success(null, $data);
        }
        $mapped = [];
        foreach ($codes as $code) {
            $field = in_array($code, ['invalid_email', 'empty_email', 'user_email'], true) ? 'user_email' : AuthResult::FORM;
            $mapped[$field] = trim(wp_strip_all_tags((string) $error->get_error_message($code)));
        }
        return AuthResult::failure($mapped, $data);
    }

    /**
     * Tells the owner of an existing account that somebody (probably they themselves) tried to
     * register again, and how to get back in. At most one e-mail per day and account.
     */
    private function notifyExistingAccount(WP_User $user): void
    {
        $flag = 'portier_reg_notice_' . $user->ID;
        if (get_site_transient($flag)) {
            return;
        }
        set_site_transient($flag, 1, DAY_IN_SECONDS);

        $switched = switch_to_user_locale($user->ID);
        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $subject = sprintf(
            /* translators: %s: site name */
            __('[%s] You already have an account', 'portier'),
            $site
        );
        // One translatable string per paragraph keeps the strings short for translators.
        $paragraphs = [
            /* translators: %s: user display name */
            sprintf(__('Hi %s,', 'portier'), $user->display_name),
            sprintf(
                /* translators: %s: site name */
                __('someone – hopefully you – just tried to create an account on %s with this email address. You already have an account, so nothing was changed.', 'portier'),
                $site
            ),
            /* translators: %s: login URL */
            sprintf(__('Log in: %s', 'portier'), wp_login_url())
            . "\n"
            /* translators: %s: lost password URL */
            . sprintf(__('Forgot your password? %s', 'portier'), wp_lostpassword_url()),
            __('If this wasn\'t you, you can ignore this email.', 'portier'),
        ];
        wp_mail($user->user_email, $subject, implode("\n\n", $paragraphs));
        if ($switched) {
            restore_previous_locale();
        }
    }
}
