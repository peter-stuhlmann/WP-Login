<?php

declare(strict_types=1);

namespace Portier\Passkeys;

use Portier\Auth\PasswordlessLogin;
use Portier\Frontend\Device;
use Portier\OAuth\Jwt;
use Portier\Settings\Settings;
use WP_User;

/**
 * Passkeys: adding them in the profile and logging in with them.
 *
 * A passkey login is complete authentication: the device has checked the person (PIN,
 * fingerprint, face) and holds a key that only works on this site. It therefore replaces
 * password and second factor.
 */
final class PasskeyService
{
    public function __construct(
        private readonly RelyingParty $rp,
        private readonly ChallengeStore $challenges,
        private readonly CredentialStore $credentials,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Switched on in the settings and technically possible: WebAuthn only works in a secure
     * context (HTTPS, or http://localhost while developing).
     */
    public function enabled(): bool
    {
        return $this->settings->bool('passkeys') && self::secureContext();
    }

    public static function secureContext(): bool
    {
        $home = wp_parse_url(home_url('/'));
        $host = is_array($home) ? strtolower((string) ($home['host'] ?? '')) : '';
        $scheme = is_array($home) ? strtolower((string) ($home['scheme'] ?? '')) : '';
        return $scheme === 'https' || $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '127.0.0.1';
    }

    public function credentials(): CredentialStore
    {
        return $this->credentials;
    }

    // ------------------------------------------------------------------ Login

    /**
     * Options for a passkey login. Without a user any passkey for this site may answer
     * (discoverable credentials); with one (second factor screen) only that user's passkeys.
     *
     * @param string $binding Browser secret (see ChallengeStore).
     * @return array<string, mixed>
     * @throws PasskeyException
     */
    public function loginOptions(string $binding, ?WP_User $user = null): array
    {
        $this->assertEnabled();
        $allow = $user !== null ? $this->credentials->ids($user->ID) : [];
        return $this->rp->requestOptions($this->challenges->create($binding, 'login'), $allow);
    }

    /**
     * Verifies a passkey login and returns the account.
     *
     * @param array<string, mixed> $credential PublicKeyCredential from the browser (base64url fields).
     * @throws PasskeyException
     */
    public function login(string $binding, array $credential): WP_User
    {
        $this->assertEnabled();
        $id = self::text($credential, 'id');
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $clientData = self::binary($response, 'clientDataJSON');
        $authenticatorData = self::binary($response, 'authenticatorData');
        $signature = self::binary($response, 'signature');
        $handle = self::text($response, 'userHandle');

        // The challenge is consumed first: every attempt costs one, also failed ones.
        $challenge = ChallengeStore::challengeOf($clientData);
        if ($this->challenges->consume($binding, 'login', $challenge) === null) {
            throw new PasskeyException(esc_html(PasskeyException::CHALLENGE));
        }

        $user = $this->credentials->userByHandle($handle);
        $stored = $user !== null ? $this->credentials->find($user->ID, $id) : null;
        if ($user === null || $stored === null) {
            throw new PasskeyException(esc_html(PasskeyException::UNKNOWN));
        }

        $count = $this->rp->verifyAssertion($clientData, $authenticatorData, $signature, $challenge, CoseKey::import($stored['key']), $stored['count']);
        $this->credentials->touch($user->ID, $id, $count);
        if (! PasswordlessLogin::allowed($user, 'passkey')) {
            throw new PasskeyException(esc_html(PasskeyException::BLOCKED));
        }
        return $user;
    }

    // ------------------------------------------------------------------ Registration (profile)

    /**
     * Options for adding a passkey to the logged-in user's account.
     *
     * @return array<string, mixed>
     * @throws PasskeyException
     */
    public function registrationOptions(WP_User $user): array
    {
        $this->assertEnabled();
        if ($this->credentials->count($user->ID) >= CredentialStore::MAX_PER_USER) {
            throw new PasskeyException(esc_html(PasskeyException::LIMIT), esc_html('Too many passkeys.'));
        }
        $challenge = $this->challenges->create(ChallengeStore::sessionBinding($user->ID), 'register', ['user' => $user->ID]);
        return $this->rp->creationOptions(
            $challenge,
            $this->credentials->handle($user),
            $user->user_login,
            $user->display_name,
            $this->credentials->ids($user->ID)
        );
    }

    /**
     * Verifies and stores a new passkey; the account owner gets an e-mail about it.
     *
     * @param array<string, mixed> $credential PublicKeyCredential from the browser.
     * @param string               $name       Name chosen by the person ('' = device name).
     * @param string               $userAgent  For the default name, e.g. "Chrome on Windows".
     * @return string Credential ID.
     * @throws PasskeyException
     */
    public function register(WP_User $user, array $credential, string $name, string $userAgent): string
    {
        $this->assertEnabled();
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $clientData = self::binary($response, 'clientDataJSON');
        $attestation = self::binary($response, 'attestationObject');

        $challenge = ChallengeStore::challengeOf($clientData);
        $issued = $this->challenges->consume(ChallengeStore::sessionBinding($user->ID), 'register', $challenge);
        if ($issued === null || (int) ($issued['user'] ?? 0) !== $user->ID) {
            throw new PasskeyException(esc_html(PasskeyException::CHALLENGE));
        }

        $data = $this->rp->verifyRegistration($clientData, $attestation, $challenge);
        if (in_array(Jwt::base64UrlEncode($data->credentialId), $this->credentials->ids($user->ID), true)) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Passkey already registered.'));
        }
        if ($name === '') {
            $device = Device::describe($userAgent);
            $name = $device['browser'] !== '' && $device['os'] !== ''
                /* translators: 1: browser, 2: operating system, e.g. "Chrome on Windows" */
                ? sprintf(__('%1$s on %2$s', 'portier'), $device['browser'], $device['os'])
                : ($device['browser'] . $device['os']);
        }
        $id = $this->credentials->add($user->ID, $data, $name);
        $this->notify($user, (string) ($this->credentials->find($user->ID, $id)['name'] ?? ''));
        return $id;
    }

    /**
     * Tells the account owner about a new passkey – if it was not them, they can react.
     */
    private function notify(WP_User $user, string $name): void
    {
        $switched = switch_to_user_locale($user->ID);
        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        /* translators: %s: site name */
        $subject = sprintf(__('[%s] New passkey added', 'portier'), $site);
        $paragraphs = [
            /* translators: %s: user display name */
            sprintf(__('Hi %s,', 'portier'), $user->display_name),
            /* translators: 1: passkey name, 2: site name */
            sprintf(__('a new passkey ("%1$s") was added to your account on %2$s. From now on it can be used to log in.', 'portier'), $name, $site),
            __('If this was not you, delete the passkey in your profile right away and change your password:', 'portier'),
            admin_url('profile.php#portier-passkeys'),
        ];
        wp_mail($user->user_email, $subject, implode("\n\n", $paragraphs));
        if ($switched) {
            restore_previous_locale();
        }
    }

    /**
     * @throws PasskeyException
     */
    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new PasskeyException(esc_html(PasskeyException::DISABLED));
        }
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) && strlen($value) <= 16384 ? $value : '';
    }

    /**
     * Binary field sent as base64url by the browser script.
     *
     * @param array<mixed> $data
     * @throws PasskeyException
     */
    private static function binary(array $data, string $key): string
    {
        $value = self::text($data, $key);
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Missing or invalid ' . $key . '.'));
        }
        return Jwt::base64UrlDecode($value);
    }
}
