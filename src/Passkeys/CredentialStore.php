<?php

declare(strict_types=1);

namespace Portier\Passkeys;

use Portier\OAuth\Jwt;
use WP_User;

/**
 * The passkeys of each user, in user meta.
 *
 * - `portier_passkey_handle`: random user handle given to authenticators instead of the user ID
 *   or e-mail address (WebAuthn user.id must not contain personal data). On login it leads back
 *   to the account.
 * - `portier_passkeys`: credential ID (base64url) => name, public key, signature counter, dates.
 * Only public keys are stored – a database leak does not allow anyone to log in.
 */
final class CredentialStore
{
    public const HANDLE = 'portier_passkey_handle';
    public const META = 'portier_passkeys';
    /** Enough for every device and password manager of a person, but not unlimited. */
    public const MAX_PER_USER = 20;

    /**
     * The user's handle, created on first use.
     */
    public function handle(WP_User $user): string
    {
        $handle = get_user_meta($user->ID, self::HANDLE, true);
        if (is_string($handle) && $handle !== '') {
            return $handle;
        }
        $handle = Jwt::base64UrlEncode(random_bytes(32));
        update_user_meta($user->ID, self::HANDLE, $handle);
        return $handle;
    }

    public function userByHandle(string $handle): ?WP_User
    {
        if ($handle === '' || strlen($handle) > 86) {
            return null;
        }
        // phpcs:ignore WordPress.DB.SlowDBQuery -- Indexed by meta_key; only on passkey login.
        $users = get_users(['meta_key' => self::HANDLE, 'meta_value' => $handle, 'number' => 2, 'blog_id' => 0]);
        // A handle belongs to exactly one account; anything else is refused.
        return count($users) === 1 && $users[0] instanceof WP_User ? $users[0] : null;
    }

    /**
     * @return array<string, array{name: string, key: array{alg: int, key: string}, count: int, created: int, used: int, synced: bool}>
     */
    public function all(int $userId): array
    {
        $stored = get_user_meta($userId, self::META, true);
        if (! is_array($stored)) {
            return [];
        }
        $passkeys = [];
        foreach ($stored as $id => $passkey) {
            if (is_string($id) && is_array($passkey) && is_array($passkey['key'] ?? null)) {
                $passkeys[$id] = [
                    'name' => (string) ($passkey['name'] ?? ''),
                    'key' => ['alg' => (int) ($passkey['key']['alg'] ?? 0), 'key' => (string) ($passkey['key']['key'] ?? '')],
                    'count' => (int) ($passkey['count'] ?? 0),
                    'created' => (int) ($passkey['created'] ?? 0),
                    'used' => (int) ($passkey['used'] ?? 0),
                    'synced' => (bool) ($passkey['synced'] ?? false),
                ];
            }
        }
        return $passkeys;
    }

    /**
     * @return list<string> Credential IDs (base64url).
     */
    public function ids(int $userId): array
    {
        return array_map('strval', array_keys($this->all($userId)));
    }

    public function count(int $userId): int
    {
        return count($this->all($userId));
    }

    /**
     * Stores a newly registered passkey.
     *
     * @throws PasskeyException When the user already has the maximum number of passkeys.
     */
    public function add(int $userId, AuthenticatorData $data, string $name): string
    {
        $passkeys = $this->all($userId);
        if (count($passkeys) >= self::MAX_PER_USER) {
            throw new PasskeyException(esc_html(PasskeyException::LIMIT), esc_html('Too many passkeys.'));
        }
        if ($data->publicKey === null) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('No public key.'));
        }
        $id = Jwt::base64UrlEncode($data->credentialId);
        $passkeys[$id] = [
            'name' => self::cleanName($name),
            'key' => $data->publicKey->export(),
            'count' => $data->signCount,
            'created' => time(),
            'used' => 0,
            // Synced passkeys (iCloud Keychain, Google Password Manager …) survive a lost device.
            'synced' => $data->has(AuthenticatorData::BACKED_UP),
        ];
        update_user_meta($userId, self::META, $passkeys);
        /**
         * Fires when a passkey was added (audit log).
         *
         * @param int    $userId
         * @param string $name
         */
        do_action('portier_passkey_added', $userId, $passkeys[$id]['name']);
        return $id;
    }

    /**
     * @return array{name: string, key: array{alg: int, key: string}, count: int, created: int, used: int, synced: bool}|null
     */
    public function find(int $userId, string $id): ?array
    {
        return $this->all($userId)[$id] ?? null;
    }

    /**
     * Records a successful login with the passkey.
     */
    public function touch(int $userId, string $id, int $count): void
    {
        $passkeys = $this->all($userId);
        if (isset($passkeys[$id])) {
            $passkeys[$id]['count'] = $count;
            $passkeys[$id]['used'] = time();
            update_user_meta($userId, self::META, $passkeys);
        }
    }

    public function rename(int $userId, string $id, string $name): bool
    {
        $passkeys = $this->all($userId);
        if (! isset($passkeys[$id])) {
            return false;
        }
        $old = $passkeys[$id]['name'];
        $passkeys[$id]['name'] = self::cleanName($name);
        update_user_meta($userId, self::META, $passkeys);
        /**
         * Fires when a passkey was renamed (audit log).
         *
         * @param int    $userId
         * @param string $old
         * @param string $new
         */
        do_action('portier_passkey_renamed', $userId, $old, $passkeys[$id]['name']);
        return true;
    }

    public function delete(int $userId, string $id): bool
    {
        $passkeys = $this->all($userId);
        if (! isset($passkeys[$id])) {
            return false;
        }
        $name = $passkeys[$id]['name'];
        unset($passkeys[$id]);
        /**
         * Fires when a passkey was deleted (audit log).
         *
         * @param int    $userId
         * @param string $name
         */
        do_action('portier_passkey_deleted', $userId, $name);
        if ($passkeys === []) {
            delete_user_meta($userId, self::META);
        } else {
            update_user_meta($userId, self::META, $passkeys);
        }
        return true;
    }

    private static function cleanName(string $name): string
    {
        $name = trim(sanitize_text_field($name));
        return mb_substr($name !== '' ? $name : __('Passkey', 'portier'), 0, 60);
    }
}
