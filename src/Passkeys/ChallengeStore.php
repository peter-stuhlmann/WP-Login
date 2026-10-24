<?php

declare(strict_types=1);

namespace Portier\Passkeys;

/**
 * Issued WebAuthn challenges, each usable exactly once within five minutes.
 *
 * A challenge is bound to whoever requested it: on the login screen to a random secret in an
 * HttpOnly cookie of that browser, in the profile to the user's session. A response is only
 * accepted with the same binding, so a challenge cannot be used from another browser or session.
 * Several challenges may be open at once (passkey autofill plus the button). Only hashes are
 * used as storage keys.
 */
final class ChallengeStore
{
    public const COOKIE = 'portier_webauthn';
    public const TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * @param string               $binding Browser secret or session binding.
     * @param string               $purpose "login" or "register".
     * @param array<string, scalar> $data    Context (e.g. the user ID for a registration).
     * @return string The challenge (base64url) for the browser.
     */
    public function create(string $binding, string $purpose, array $data = []): string
    {
        $challenge = RelyingParty::challenge();
        set_site_transient($this->key($binding, $challenge), ['purpose' => $purpose, 'created' => time()] + $data, self::TTL);
        return $challenge;
    }

    /**
     * Returns and deletes the record – exactly once – if binding and purpose match.
     *
     * @return array<string, scalar>|null
     */
    public function consume(string $binding, string $purpose, string $challenge): ?array
    {
        if ($binding === '' || $challenge === '') {
            return null;
        }
        $key = $this->key($binding, $challenge);
        $data = get_site_transient($key);
        // Deleting is the single use: of two parallel answers only one deletes the record.
        if (! delete_site_transient($key) || ! is_array($data) || ($data['purpose'] ?? '') !== $purpose || (int) ($data['created'] ?? 0) < time() - self::TTL) {
            return null;
        }
        return $data;
    }

    /**
     * Challenge from clientDataJSON (needed to find the record before verifying).
     */
    public static function challengeOf(string $clientDataJson): string
    {
        $client = json_decode($clientDataJson, true);
        return is_array($client) && is_string($client['challenge'] ?? null) ? $client['challenge'] : '';
    }

    /**
     * Binding for a logged-in user's session (profile registration).
     */
    public static function sessionBinding(int $userId): string
    {
        return 'session|' . $userId . '|' . wp_get_session_token();
    }

    private function key(string $binding, string $challenge): string
    {
        return 'portier_wa_' . hash('sha256', $binding . '|' . $challenge);
    }
}
