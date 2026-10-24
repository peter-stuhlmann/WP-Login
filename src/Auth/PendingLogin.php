<?php

declare(strict_types=1);

namespace Portier\Auth;

/**
 * A login that passed the first factor but still needs the second one (or its setup).
 *
 * Nobody is logged in at this point: no auth cookie exists yet. The browser only holds a
 * random token (HttpOnly cookie); the server keeps the state – user, "remember me", redirect,
 * attempts – in a short-lived site transient under a hash of that token.
 */
final class PendingLogin
{
    public const COOKIE = 'portier_pending';
    public const TTL = 10 * MINUTE_IN_SECONDS;
    public const MAX_ATTEMPTS = 5;

    /** Stages of a pending login. */
    public const STAGE_VERIFY = 'verify';
    public const STAGE_SETUP = 'setup';
    public const STAGE_CODES = 'codes';

    public function create(int $userId, bool $remember, string $redirect, string $stage, string $method = 'password'): string
    {
        $token = bin2hex(random_bytes(32));
        set_site_transient($this->key($token), [
            'user_id' => $userId,
            'remember' => $remember,
            'redirect' => $redirect,
            'stage' => $stage,
            'method' => $method,
            'attempts' => 0,
            'expires' => time() + self::TTL,
        ], self::TTL);
        return $token;
    }

    /**
     * @return array{user_id: int, remember: bool, redirect: string, stage: string, method: string, attempts: int, expires: int, codes?: list<string>}|null
     */
    public function get(?string $token): ?array
    {
        if ($token === null || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $state = get_site_transient($this->key($token));
        if (! is_array($state) || (int) ($state['expires'] ?? 0) < time()) {
            return null;
        }
        // Normalise the stored data to the documented shape.
        $normalised = [
            'user_id' => (int) ($state['user_id'] ?? 0),
            'remember' => (bool) ($state['remember'] ?? false),
            'redirect' => (string) ($state['redirect'] ?? ''),
            'stage' => (string) ($state['stage'] ?? ''),
            'method' => (string) ($state['method'] ?? 'password'),
            'attempts' => (int) ($state['attempts'] ?? 0),
            'expires' => (int) $state['expires'],
        ];
        if (isset($state['codes']) && is_array($state['codes'])) {
            $normalised['codes'] = array_values(array_map('strval', $state['codes']));
        }
        return $normalised;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(string $token, array $changes): void
    {
        $state = $this->get($token);
        if ($state !== null) {
            set_site_transient($this->key($token), array_merge($state, $changes), max(1, $state['expires'] - time()));
        }
    }

    /**
     * Counts a wrong code. Returns the attempts left; at 0 the pending login is discarded.
     */
    public function fail(string $token): int
    {
        $state = $this->get($token);
        if ($state === null) {
            return 0;
        }
        $left = self::MAX_ATTEMPTS - ($state['attempts'] + 1);
        if ($left <= 0) {
            $this->delete($token);
            return 0;
        }
        $this->update($token, ['attempts' => $state['attempts'] + 1]);
        return $left;
    }

    public function delete(string $token): void
    {
        delete_site_transient($this->key($token));
    }

    private function key(string $token): string
    {
        return 'portier_pl_' . hash('sha256', $token);
    }
}
