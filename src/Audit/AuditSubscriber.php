<?php

declare(strict_types=1);

namespace Portier\Audit;

use Portier\Auth\LoginFlow;
use Portier\OAuth\Identity;
use Portier\OAuth\Providers;
use Portier\Passkeys\PasskeyException;
use Portier\Security\LoginThrottle;
use Portier\Settings\Settings;
use Portier\TwoFactor\TwoFactorGate;
use WP_Error;
use WP_User;

/**
 * Listens to everything worth recording and writes it to the audit log:
 *
 * - every Portier setting (old → new), registration open/closed, default role;
 * - account security: 2FA on/off/reset, new recovery codes, passkeys added/renamed/deleted,
 *   social accounts connected/disconnected;
 * - logins (with method), failed logins and pauses after too many attempts, wrong 2FA codes
 *   and their pauses (login names are only written when they belong to an account),
 *   failed passkey and social logins, logouts, login links, password resets, new accounts;
 * - plugin activated/deactivated (see Lifecycle).
 *
 * Messages are written in the site language, whatever language the visitor uses.
 */
final class AuditSubscriber
{
    public function register(): void
    {
        add_action('add_option_' . Settings::OPTION, [$this, 'settingsAdded'], 10, 2);
        add_action('update_option_' . Settings::OPTION, [$this, 'settingsChanged'], 10, 2);
        add_action('update_option_users_can_register', [$this, 'registrationChanged'], 10, 2);
        add_action('update_option_default_role', [$this, 'defaultRoleChanged'], 10, 2);
        add_action('update_site_option_registration', [$this, 'networkRegistrationChanged'], 10, 3);

        add_action('wp_login', [$this, 'loggedIn'], 10, 2);
        add_action('wp_login_failed', [$this, 'loginFailed'], 10, 2);
        add_action('portier_login_locked', [$this, 'locked'], 10, 2);
        add_action('wp_logout', [$this, 'loggedOut']);
        add_action('portier_2fa_failed', [$this, 'secondFactorFailed'], 10, 2);
        add_action('portier_2fa_locked', [$this, 'secondFactorLocked'], 10, 2);
        add_action('portier_passkey_login_failed', [$this, 'passkeyFailed']);
        add_action('portier_social_login_failed', [$this, 'socialFailed'], 10, 2);
        add_action('portier_magic_link_sent', [$this, 'magicLinkSent']);
        add_action('retrieve_password', [$this, 'resetRequested']);
        add_action('after_password_reset', [$this, 'passwordReset']);
        add_action('user_register', [$this, 'userRegistered']);

        add_action('portier_2fa_enabled', [$this, 'secondFactorEnabled']);
        add_action('portier_2fa_disabled', [$this, 'secondFactorDisabled']);
        add_action('portier_2fa_codes_renewed', [$this, 'codesRenewed']);
        add_action('portier_passkey_added', [$this, 'passkeyAdded'], 10, 2);
        add_action('portier_passkey_renamed', [$this, 'passkeyRenamed'], 10, 3);
        add_action('portier_passkey_deleted', [$this, 'passkeyDeleted'], 10, 2);
        add_action('portier_social_linked', [$this, 'socialLinked']);
        add_action('portier_social_unlinked', [$this, 'socialUnlinked'], 10, 2);
    }

    // ------------------------------------------------------------------ Settings

    /**
     * First save of the settings: compared with the defaults.
     */
    public function settingsAdded(string $option, mixed $value): void
    {
        $this->settingsChanged(Settings::defaults(), $value);
    }

    public function settingsChanged(mixed $old, mixed $new): void
    {
        $defaults = Settings::defaults();
        $old = array_merge($defaults, is_array($old) ? $old : []);
        $new = array_merge($defaults, is_array($new) ? $new : []);
        // Switching the log off is the last thing it records – so it stays clear who did it.
        $switchedOff = (bool) $old['audit_log'] && ! (bool) $new['audit_log'];
        $this->log('settings.changed', static fn (): array => SettingsDiff::messages($old, $new), null, null, $switchedOff);
    }

    public function registrationChanged(mixed $old, mixed $new): void
    {
        if ((bool) $old !== (bool) $new) {
            $this->log('registration.changed', static fn (): array => [
                $new ? __('Registration allowed', 'portier') : __('Registration not allowed', 'portier'),
            ]);
        }
    }

    public function networkRegistrationChanged(string $option, mixed $value, mixed $old): void
    {
        $open = static fn (mixed $setting): bool => in_array($setting, ['user', 'all'], true);
        $this->registrationChanged($open($old), $open($value));
    }

    public function defaultRoleChanged(mixed $old, mixed $new): void
    {
        if ($old !== $new) {
            $names = wp_roles()->get_names();
            $this->log('registration.role', static fn (): array => [
                sprintf(
                    /* translators: 1: old role, 2: new role */
                    __('Role for new accounts: %1$s → %2$s', 'portier'),
                    translate_user_role($names[(string) $old] ?? (string) $old),
                    translate_user_role($names[(string) $new] ?? (string) $new)
                ),
            ]);
        }
    }

    // ------------------------------------------------------------------ Logins

    public function loggedIn(string $login, WP_User $user): void
    {
        $method = LoginFlow::takeMethod();
        $this->log('login.success', fn (): array => [
            /* translators: %s: login method, e.g. "Passkey" or "password + 2FA code" */
            sprintf(__('Logged in with %s', 'portier'), $this->methodLabel($method)),
        ], $user);
    }

    public function loginFailed(string $username, mixed $error = null): void
    {
        $code = $error instanceof WP_Error ? (string) $error->get_error_code() : '';
        $this->log('login.failed', static function () use ($code, $username): array {
            $login = self::knownLogin($username);
            $reason = match ($code) {
                'invalid_username', 'invalid_email', 'incorrect_password' => __('wrong login or password', 'portier'),
                'empty_username', 'empty_password' => __('empty field', 'portier'),
                LoginThrottle::ERROR_CODE => __('paused after too many attempts', 'portier'),
                TwoFactorGate::ERROR_CODE => __('two-factor authentication required, login only on the login page', 'portier'),
                '' => __('unknown reason', 'portier'),
                default => $code,
            };
            return [
                $login !== ''
                    /* translators: 1: entered login name, 2: reason */
                    ? sprintf(__('Login failed for “%1$s” (%2$s)', 'portier'), $login, $reason)
                    /* translators: %s: reason */
                    : sprintf(__('Login failed for an unknown account (%s)', 'portier'), $reason),
            ];
        }, null);
    }

    public function locked(string $username, int $seconds): void
    {
        $this->log('login.locked', static function () use ($username, $seconds): array {
            $login = self::knownLogin($username);
            $minutes = (int) ceil($seconds / 60);
            return [
                $login !== ''
                    /* translators: 1: minutes, 2: last entered login name */
                    ? sprintf(__('Logins paused for %1$d minutes after too many failed attempts (last login name: “%2$s”)', 'portier'), $minutes, $login)
                    /* translators: %d: minutes */
                    : sprintf(__('Logins paused for %d minutes after too many failed attempts (last attempt: unknown account)', 'portier'), $minutes),
            ];
        }, null);
    }

    public function secondFactorLocked(int $userId, int $seconds): void
    {
        $this->forUser('2fa.locked', $userId, static fn (): array => [
            /* translators: %d: minutes */
            sprintf(__('Two-factor codes paused for %d minutes after too many wrong codes – the password is probably known', 'portier'), (int) ceil($seconds / 60)),
        ]);
    }

    public function loggedOut(int $userId = 0): void
    {
        $user = get_userdata($userId);
        $this->log('logout', static fn (): array => [__('Logged out', 'portier')], $user instanceof WP_User ? $user : null);
    }

    public function secondFactorFailed(int $userId, string $field): void
    {
        $user = get_userdata($userId);
        $this->log('2fa.failed', static fn (): array => [
            $field === 'recovery_code' ? __('Wrong recovery code', 'portier') : __('Wrong two-factor code', 'portier'),
        ], $user instanceof WP_User ? $user : null);
    }

    public function passkeyFailed(string $reason): void
    {
        $this->log('passkey.failed', static fn (): array => [
            sprintf(
                /* translators: %s: reason */
                __('Passkey login failed (%s)', 'portier'),
                match ($reason) {
                    PasskeyException::CHALLENGE => __('request expired or used before', 'portier'),
                    PasskeyException::UNKNOWN => __('unknown passkey', 'portier'),
                    PasskeyException::VERIFICATION => __('device did not confirm the person', 'portier'),
                    PasskeyException::SIGNATURE => __('invalid signature', 'portier'),
                    PasskeyException::ORIGIN => __('signed for another website – possibly phishing', 'portier'),
                    PasskeyException::COUNTER => __('signature counter went back – possibly a cloned key', 'portier'),
                    PasskeyException::LIMIT => __('too many attempts', 'portier'),
                    PasskeyException::BLOCKED => __('account may not log in', 'portier'),
                    default => $reason,
                }
            ),
        ], null);
    }

    public function socialFailed(string $reason, string $provider = ''): void
    {
        $name = $this->providerLabel($provider);
        $this->log('social.failed', static fn (): array => [
            /* translators: 1: provider, 2: reason code */
            sprintf(__('Login with %1$s failed (%2$s)', 'portier'), $name, $reason),
        ], null);
    }

    public function magicLinkSent(WP_User $user): void
    {
        $this->log('magic_link.sent', static fn (): array => [__('Login link sent by email', 'portier')], null, $user);
    }

    public function resetRequested(string $login): void
    {
        $this->log('password.reset_requested', static fn (): array => [
            /* translators: %s: login name */
            sprintf(__('Password reset requested for “%s”', 'portier'), $login),
        ], null);
    }

    public function passwordReset(WP_User $user): void
    {
        $this->log('password.reset', static fn (): array => [__('Password reset', 'portier')], $user);
    }

    public function userRegistered(int $userId): void
    {
        $user = get_userdata($userId);
        if ($user instanceof WP_User) {
            $this->log('user.registered', static fn (): array => [__('Account created', 'portier')], null, $user);
        }
    }

    // ------------------------------------------------------------------ Account security

    public function secondFactorEnabled(int $userId): void
    {
        $this->forUser('2fa.enabled', $userId, static fn (): array => [__('Two-factor authentication set up', 'portier')]);
    }

    public function secondFactorDisabled(int $userId): void
    {
        $this->forUser('2fa.disabled', $userId, static fn (): array => [__('Two-factor authentication turned off', 'portier')]);
    }

    public function codesRenewed(int $userId): void
    {
        $this->forUser('2fa.codes', $userId, static fn (): array => [__('New recovery codes created', 'portier')]);
    }

    public function passkeyAdded(int $userId, string $name): void
    {
        /* translators: %s: passkey name */
        $this->forUser('passkey.added', $userId, static fn (): array => [sprintf(__('Passkey “%s” added', 'portier'), $name)]);
    }

    public function passkeyRenamed(int $userId, string $old, string $new): void
    {
        /* translators: 1: old name, 2: new name */
        $this->forUser('passkey.renamed', $userId, static fn (): array => [sprintf(__('Passkey renamed: “%1$s” → “%2$s”', 'portier'), $old, $new)]);
    }

    public function passkeyDeleted(int $userId, string $name): void
    {
        /* translators: %s: passkey name */
        $this->forUser('passkey.deleted', $userId, static fn (): array => [sprintf(__('Passkey “%s” deleted', 'portier'), $name)]);
    }

    public function socialLinked(WP_User $user, ?Identity $identity = null): void
    {
        $provider = $identity !== null ? $identity->provider : '';
        $email = $identity !== null ? $identity->email : '';
        $name = $this->providerLabel($provider);
        $this->log('social.linked', static fn (): array => [
            /* translators: 1: provider, 2: e-mail address at the provider */
            sprintf(__('%1$s account connected (%2$s)', 'portier'), $name, $email !== '' ? $email : '–'),
        ], null, $user);
    }

    public function socialUnlinked(WP_User $user, string $provider): void
    {
        $name = $this->providerLabel($provider);
        /* translators: %s: provider */
        $this->log('social.unlinked', static fn (): array => [sprintf(__('%s account disconnected', 'portier'), $name)], null, $user);
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * Records an event; the messages are built in the site language.
     *
     * @param callable(): list<string> $messages
     */
    public function log(string $event, callable $messages, ?WP_User $actor = null, ?WP_User $subject = null, bool $force = false): void
    {
        if (! $force && ! AuditLog::enabled()) {
            return; // Switched off: not even the messages are built.
        }
        $locale = (string) get_option('WPLANG');
        $switched = switch_to_locale($locale !== '' ? $locale : 'en_US');
        if (! is_textdomain_loaded('portier')) {
            \Portier\Plugin::instance()->loadTextdomain();
        }
        try {
            $lines = $messages();
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
        AuditLog::instance()->record($event, $lines, $actor, $subject, $force);
    }

    /**
     * @param callable(): list<string> $messages
     */
    private function forUser(string $event, int $userId, callable $messages): void
    {
        $user = get_userdata($userId);
        $this->log($event, $messages, null, $user instanceof WP_User ? $user : null);
    }

    /**
     * The login name as entered – only if it belongs to an account. Anything else is not
     * written: people sometimes type their password into the name field.
     */
    private static function knownLogin(string $entered): string
    {
        $entered = trim($entered);
        $user = is_email($entered) ? get_user_by('email', $entered) : get_user_by('login', $entered);
        return $entered !== '' && $user instanceof WP_User ? $entered : '';
    }

    /**
     * "password+totp" → "password + 2FA code", "social:google" → "Google".
     */
    private function methodLabel(string $method): string
    {
        if ($method === '') {
            return __('the WordPress login', 'portier');
        }
        $parts = explode('+', $method);
        $labels = [];
        foreach ($parts as $part) {
            $labels[] = match (true) {
                $part === 'password' => __('password', 'portier'),
                $part === 'magic_link' => __('login link', 'portier'),
                $part === 'passkey' => __('passkey', 'portier'),
                $part === 'totp' => __('2FA code', 'portier'),
                $part === 'recovery' => __('recovery code', 'portier'),
                $part === 'totp-setup' => __('2FA set up during login', 'portier'),
                str_starts_with($part, 'social:') => $this->providerLabel(substr($part, 7)),
                default => $part,
            };
        }
        return implode(' + ', $labels);
    }

    private function providerLabel(string $id): string
    {
        $provider = Providers::get($id);
        return $provider !== null ? $provider->label : ($id !== '' ? $id : '–');
    }
}
