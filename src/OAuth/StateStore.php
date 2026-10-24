<?php

declare(strict_types=1);

namespace Portier\OAuth;

/**
 * Remembers an authorization request between leaving for the provider and coming back.
 *
 * The random `state` travels to the provider and back; the same value sits in an HttpOnly
 * cookie. The callback is only accepted when both match and the server-side record exists, so
 * a callback cannot be replayed, forged for another browser (login CSRF) or used after 10 minutes.
 * Only a hash of the state is used as storage key.
 */
final class StateStore
{
    public const COOKIE = 'portier_oauth';
    public const TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * @param array<string, scalar> $data
     * @return string The state value (for the URL and the cookie).
     */
    public function create(array $data): string
    {
        $state = Jwt::base64UrlEncode(random_bytes(32));
        set_site_transient($this->key($state), $data + ['created' => time()], self::TTL);
        return $state;
    }

    /**
     * Returns and deletes the record – exactly once.
     *
     * @return array<string, scalar>|null
     */
    public function consume(string $state, string $cookie): ?array
    {
        if ($state === '' || $cookie === '' || ! hash_equals($cookie, $state)) {
            return null;
        }
        $key = $this->key($state);
        $data = get_site_transient($key);
        // Deleting is the single use: of two parallel callbacks only one deletes the record.
        if (! delete_site_transient($key) || ! is_array($data) || (int) ($data['created'] ?? 0) < time() - self::TTL) {
            return null;
        }
        return $data;
    }

    private function key(string $state): string
    {
        return 'portier_oauth_' . hash('sha256', $state);
    }
}
