<?php

declare(strict_types=1);

namespace Portier\TwoFactor;

use Portier\Security\Lock;
use Portier\Security\RateLimiter;
use Portier\Settings\Settings;
use WP_User;

/**
 * Two-factor policy and verification.
 *
 * Decides per user whether a second factor is needed, already set up or still to be set up,
 * and verifies codes (TOTP or recovery). Storage lives in {@see UserStore}. The policy (mode,
 * roles, trust period) is read from the settings on every call, so changes apply immediately.
 */
final class TwoFactorService
{
    public const NONE = 'none';
    public const VERIFY = 'verify';
    public const SETUP = 'setup';

    /** Kinds of codes for {@see TwoFactorService::check()}. */
    public const TOTP = 'totp';
    public const RECOVERY = 'recovery';

    public const DEVICE_COOKIE = 'portier_trusted_device';

    /** How long a check waits for a parallel check of the same account (seconds). */
    private const LOCK_WAIT = 2.0;

    /**
     * @param RateLimiter           $failures Wrong codes per account.
     * @param (\Closure(): int)|null $now     Clock, injectable for tests.
     */
    public function __construct(
        private readonly UserStore $store,
        private readonly Settings $settings,
        private readonly RateLimiter $failures,
        private readonly Lock $lock = new Lock(),
        private readonly ?\Closure $now = null,
    ) {
    }

    public function store(): UserStore
    {
        return $this->store;
    }

    public function isActive(): bool
    {
        return $this->settings->string('two_factor_mode') !== 'off';
    }

    public function trustDays(): int
    {
        return $this->settings->int('trust_device_days');
    }

    /**
     * What the login of this user needs after the first factor.
     *
     * @param string $deviceToken Value of the trusted-device cookie ('' if none).
     */
    public function requirement(WP_User $user, string $deviceToken = ''): string
    {
        if (! $this->isActive()) {
            return self::NONE;
        }
        // Also when the secret cannot be decrypted any more: 2FA stays required (fail closed).
        if ($this->store->isEnabled($user->ID)) {
            return $this->store->isTrustedDevice($user->ID, $this->deviceTokenFor($user->ID, $deviceToken)) ? self::NONE : self::VERIFY;
        }
        return $this->isRequiredFor($user) ? self::SETUP : self::NONE;
    }

    public function isRequiredFor(WP_User $user): bool
    {
        $roles = (array) $this->settings->get('two_factor_roles');
        return $this->settings->string('two_factor_mode') === 'required' && array_intersect($user->roles, $roles) !== [];
    }

    /**
     * Checks a code of the second factor (TOTP or recovery code) for the login of $user.
     *
     * Wrong codes are counted per account – across all pending logins, browsers and IP
     * addresses, so knowing the password does not allow trying codes endlessly. Too many pause
     * the second factor with a growing lock time, and the owner is told by e-mail (whoever got
     * this far knows the password). Checks of one account run one after another, so parallel
     * requests cannot try more codes than allowed.
     *
     * @param string $kind {@see TwoFactorService::TOTP} or {@see TwoFactorService::RECOVERY}.
     */
    public function check(WP_User $user, string $kind, string $code): CodeCheck
    {
        if ($this->isUnreadable($user)) {
            return CodeCheck::unreadable();
        }
        $key = '2fa|' . $user->ID;
        if (! $this->lock->acquire($key, self::LOCK_WAIT)) {
            return CodeCheck::busy();
        }
        try {
            $locked = $this->failures->lockedFor($key);
            if ($locked > 0) {
                return CodeCheck::locked($locked);
            }
            $valid = $kind === self::RECOVERY ? $this->store->useRecoveryCode($user->ID, $code) : $this->verifyCode($user, $code);
            if ($valid) {
                $this->failures->clear($key);
                return CodeCheck::valid();
            }
            $pause = $this->failures->hit($key);
            if ($pause > 0) {
                /**
                 * Fires when too many wrong second-factor codes pause the account's codes (audit log).
                 *
                 * @param int $userId
                 * @param int $seconds Length of the pause.
                 */
                do_action('portier_2fa_locked', $user->ID, $pause);
                $this->notifyPaused($user, $pause);
            }
            return CodeCheck::wrong($pause);
        } finally {
            $this->lock->release($key);
        }
    }

    /**
     * Whether the user has 2FA, but its secret can no longer be decrypted: the encryption key or
     * the salts changed ({@see \Portier\Security\Crypto}). Logins then fail until an administrator
     * resets 2FA or the user turns it off with the password.
     */
    public function isUnreadable(WP_User $user): bool
    {
        return $this->store->isEnabled($user->ID) && $this->store->secret($user->ID) === null;
    }

    /**
     * Confirms the setup with a first code. Returns the recovery codes, or null if the code
     * was wrong.
     *
     * @return list<string>|null
     */
    public function confirmSetup(WP_User $user, string $code): ?array
    {
        $secret = $this->store->pendingSecret($user->ID);
        $step = Totp::verify($secret, $code, $this->time());
        if ($step === null) {
            return null;
        }
        return $this->store->enable($user->ID, $secret, $step);
    }

    /**
     * Cookie value for "trust this device": user id and random token. A token that belongs to
     * another account (shared computer) is simply ignored.
     */
    public function trustDevice(WP_User $user): string
    {
        return $user->ID . '.' . $this->store->trustDevice($user->ID, $this->trustDays());
    }

    /**
     * Verifies an authenticator code (each code is accepted only once).
     */
    private function verifyCode(WP_User $user, string $code): bool
    {
        $secret = $this->store->secret($user->ID);
        if ($secret === null) {
            return false;
        }
        $step = Totp::verify($secret, $code, $this->time(), $this->store->lastStep($user->ID));
        if ($step === null) {
            return false;
        }
        $this->store->setLastStep($user->ID, $step);
        return true;
    }

    /**
     * Tells the account owner that its second factor was paused: someone knows the password.
     */
    private function notifyPaused(WP_User $user, int $seconds): void
    {
        $switched = switch_to_user_locale($user->ID);
        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] Wrong two-factor codes for your account', 'portier'), $site);
        $paragraphs = [
            /* translators: %s: user display name */
            sprintf(__('Hi %s,', 'portier'), $user->display_name),
            sprintf(
                /* translators: %s: site name */
                __('someone entered several wrong two-factor codes for your account on %s. To get that far, they needed your password.', 'portier'),
                $site
            ),
            sprintf(
                /* translators: %s: human readable duration, e.g. "15 mins" */
                __('For your security, codes are not accepted for %s. If this was not you, change your password right away:', 'portier'),
                human_time_diff(time(), time() + max(60, $seconds))
            ),
            wp_lostpassword_url(),
        ];
        wp_mail($user->user_email, $subject, implode("\n\n", $paragraphs));
        if ($switched) {
            restore_previous_locale();
        }
    }

    private function deviceTokenFor(int $userId, string $cookie): string
    {
        [$id, $token] = array_pad(explode('.', $cookie, 2), 2, '');
        return (int) $id === $userId ? $token : '';
    }

    private function time(): int
    {
        return $this->now !== null ? (int) ($this->now)() : time();
    }
}
