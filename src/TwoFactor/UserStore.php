<?php

declare(strict_types=1);

namespace Portier\TwoFactor;

use Portier\Security\Crypto;

/**
 * Per-user two-factor data in user meta (users are network-wide, so this also fits multisite).
 *
 * - the TOTP secret is encrypted ({@see Crypto}),
 * - recovery codes and trusted-device tokens are stored as keyed hashes only,
 * - the last accepted time step prevents replaying a code.
 */
final class UserStore
{
    private const SECRET = 'portier_2fa_secret';
    private const PENDING = 'portier_2fa_pending';
    private const LAST_STEP = 'portier_2fa_last_step';
    private const RECOVERY = 'portier_2fa_recovery';
    private const DEVICES = 'portier_2fa_devices';

    /** A secret that was shown for setup but not confirmed yet expires after an hour. */
    private const PENDING_TTL = HOUR_IN_SECONDS;

    public function __construct(private readonly Crypto $crypto)
    {
    }

    /**
     * Whether a secret is stored – also when it can no longer be decrypted (changed key or
     * salts): 2FA must then fail closed instead of silently switching itself off.
     */
    public function isEnabled(int $userId): bool
    {
        $stored = get_user_meta($userId, self::SECRET, true);
        return is_string($stored) && $stored !== '';
    }

    /**
     * @return string|null Null when there is no secret or it cannot be decrypted.
     */
    public function secret(int $userId): ?string
    {
        $stored = get_user_meta($userId, self::SECRET, true);
        return is_string($stored) && $stored !== '' ? $this->crypto->decrypt($stored) : null;
    }

    public function lastStep(int $userId): ?int
    {
        $step = get_user_meta($userId, self::LAST_STEP, true);
        return is_numeric($step) ? (int) $step : null;
    }

    public function setLastStep(int $userId, int $step): void
    {
        update_user_meta($userId, self::LAST_STEP, $step);
    }

    /**
     * Secret for the setup screen: reused while the setup is in progress, so reloading the page
     * does not invalidate a QR code that was already scanned.
     */
    public function pendingSecret(int $userId): string
    {
        $pending = get_user_meta($userId, self::PENDING, true);
        if (is_array($pending) && ($pending['expires'] ?? 0) > time()) {
            $secret = $this->crypto->decrypt((string) ($pending['secret'] ?? ''));
            if ($secret !== null) {
                return $secret;
            }
        }
        $secret = Totp::generateSecret();
        update_user_meta($userId, self::PENDING, ['secret' => $this->crypto->encrypt($secret), 'expires' => time() + self::PENDING_TTL]);
        return $secret;
    }

    /**
     * Activates 2FA with the confirmed secret and returns fresh recovery codes.
     *
     * @return list<string>
     */
    public function enable(int $userId, string $secret, int $confirmedStep): array
    {
        update_user_meta($userId, self::SECRET, $this->crypto->encrypt($secret));
        delete_user_meta($userId, self::PENDING);
        $this->setLastStep($userId, $confirmedStep);
        $codes = $this->regenerateRecoveryCodes($userId);
        /** Fires when a user activated two-factor authentication (audit log). @param int $userId */
        do_action('portier_2fa_enabled', $userId);
        return $codes;
    }

    public function disable(int $userId): void
    {
        $wasEnabled = $this->isEnabled($userId);
        foreach ([self::SECRET, self::PENDING, self::LAST_STEP, self::RECOVERY, self::DEVICES] as $key) {
            delete_user_meta($userId, $key);
        }
        if ($wasEnabled) {
            /** Fires when two-factor authentication was turned off or reset (audit log). @param int $userId */
            do_action('portier_2fa_disabled', $userId);
        }
    }

    /**
     * @return list<string> Plain codes (shown once).
     */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $codes = RecoveryCodes::generate();
        $key = $this->crypto->hashKey('recovery');
        update_user_meta($userId, self::RECOVERY, array_map(static fn (string $code): string => RecoveryCodes::hash($code, $key), $codes));
        return $codes;
    }

    public function recoveryCodesLeft(int $userId): int
    {
        return count($this->recoveryHashes($userId));
    }

    /**
     * Checks a recovery code and removes it on success (each code works once).
     */
    public function useRecoveryCode(int $userId, string $code): bool
    {
        $hashes = $this->recoveryHashes($userId);
        $index = RecoveryCodes::match($code, $hashes, $this->crypto->hashKey('recovery'));
        if ($index === null) {
            return false;
        }
        unset($hashes[$index]);
        update_user_meta($userId, self::RECOVERY, array_values($hashes));
        return true;
    }

    /**
     * Remembers a device; returns the token for its cookie.
     */
    public function trustDevice(int $userId, int $days): string
    {
        $token = bin2hex(random_bytes(32));
        $devices = array_filter($this->devices($userId), static fn (int $expires): bool => $expires > time());
        $devices[hash_hmac('sha256', $token, $this->crypto->hashKey('device'))] = time() + $days * DAY_IN_SECONDS;
        // Keep the list short: the 20 most recent devices.
        arsort($devices);
        update_user_meta($userId, self::DEVICES, array_slice($devices, 0, 20, true));
        return $token;
    }

    public function isTrustedDevice(int $userId, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $expires = $this->devices($userId)[hash_hmac('sha256', $token, $this->crypto->hashKey('device'))] ?? 0;
        return $expires > time();
    }

    public function forgetDevices(int $userId): void
    {
        delete_user_meta($userId, self::DEVICES);
    }

    /**
     * @return list<string>
     */
    private function recoveryHashes(int $userId): array
    {
        $hashes = get_user_meta($userId, self::RECOVERY, true);
        return is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
    }

    /**
     * @return array<string, int>
     */
    private function devices(int $userId): array
    {
        $devices = get_user_meta($userId, self::DEVICES, true);
        return is_array($devices) ? array_map('intval', $devices) : [];
    }
}
