<?php

declare(strict_types=1);

namespace Portier\Passkeys;

use Portier\OAuth\Jwt;

/**
 * The website's side of WebAuthn (the "relying party"): options for the browser and the
 * verification of what it returns.
 *
 * Checks on every response (WebAuthn §7.1/§7.2):
 * - the challenge is the one this site issued (the caller consumes it exactly once);
 * - type and origin of the client data: signed in a browser on this very site – a phishing
 *   page on another domain cannot produce a valid response, not even with the real passkey;
 * - the RP ID hash: the key belongs to this domain;
 * - user presence and user verification (PIN, fingerprint, face): a passkey replaces password
 *   and second factor, so the device must have verified the person;
 * - on login the signature with the stored public key and a signature counter that does not
 *   go backwards (sign of a cloned key).
 * Attestation is not requested ("none"): passkeys from any provider are welcome, and the stored
 * public key is what protects the account.
 */
final class RelyingParty
{
    /** How long the browser shows its dialog before giving up (ms). */
    public const TIMEOUT = 300000;

    /**
     * @param string       $id      RP ID: the site's domain (e.g. "example.com").
     * @param string       $name    Shown by some authenticators.
     * @param list<string> $origins Allowed origins, e.g. "https://example.com".
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        private readonly array $origins,
    ) {
    }

    /**
     * RP for this WordPress site: domain of the home URL; origins of home and site URL (they
     * can differ in scheme or subdomain).
     */
    public static function forSite(): self
    {
        $origins = [];
        foreach ([home_url('/'), site_url('/')] as $url) {
            $parts = wp_parse_url($url);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origins[] = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
            }
        }
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        return new self(strtolower($host), wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES), array_values(array_unique($origins)));
    }

    /**
     * Options for navigator.credentials.create() (binary values base64url encoded).
     *
     * @param list<string> $exclude Credential IDs the user already has (base64url).
     * @return array<string, mixed>
     */
    public function creationOptions(string $challenge, string $userHandle, string $userName, string $displayName, array $exclude): array
    {
        return [
            'challenge' => $challenge,
            'rp' => ['id' => $this->id, 'name' => $this->name !== '' ? $this->name : $this->id],
            'user' => ['id' => $userHandle, 'name' => $userName, 'displayName' => $displayName !== '' ? $displayName : $userName],
            'pubKeyCredParams' => array_map(static fn (int $alg): array => ['type' => 'public-key', 'alg' => $alg], CoseKey::ALGORITHMS),
            'timeout' => self::TIMEOUT,
            'excludeCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => $id], $exclude),
            // A passkey: stored on the device/in the password manager, usable without a username.
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none',
        ];
    }

    /**
     * Options for navigator.credentials.get().
     *
     * @param list<string> $allow Credential IDs (base64url); empty = any passkey for this site.
     * @return array<string, mixed>
     */
    public function requestOptions(string $challenge, array $allow = []): array
    {
        return [
            'challenge' => $challenge,
            'rpId' => $this->id,
            'timeout' => self::TIMEOUT,
            'userVerification' => 'required',
            'allowCredentials' => array_map(static fn (string $id): array => ['type' => 'public-key', 'id' => $id], $allow),
        ];
    }

    /**
     * Verifies a new credential (navigator.credentials.create()).
     *
     * @param string $clientDataJson    Raw clientDataJSON.
     * @param string $attestationObject Raw attestation object (CBOR).
     * @param string $challenge         The challenge issued for this registration (base64url).
     * @throws PasskeyException
     */
    public function verifyRegistration(string $clientDataJson, string $attestationObject, string $challenge): AuthenticatorData
    {
        $this->checkClientData($clientDataJson, 'webauthn.create', $challenge);
        $attestation = Cbor::decode($attestationObject);
        if (! is_array($attestation) || ! is_string($attestation['authData'] ?? null) || ! is_string($attestation['fmt'] ?? null)) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Invalid attestation object.'));
        }
        $data = AuthenticatorData::parse($attestation['authData']);
        $this->checkAuthenticatorData($data);
        if ($data->credentialId === '' || $data->publicKey === null) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('No credential in the registration.'));
        }
        return $data;
    }

    /**
     * Verifies a login (navigator.credentials.get()) against the stored key.
     *
     * @param int $storedCount Signature counter stored with the credential.
     * @return int The new signature counter to store.
     * @throws PasskeyException
     */
    public function verifyAssertion(
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
        string $challenge,
        CoseKey $key,
        int $storedCount
    ): int {
        $this->checkClientData($clientDataJson, 'webauthn.get', $challenge);
        $data = AuthenticatorData::parse($authenticatorData);
        $this->checkAuthenticatorData($data);
        if (! $key->verify($authenticatorData . hash('sha256', $clientDataJson, true), $signature)) {
            throw new PasskeyException(esc_html(PasskeyException::SIGNATURE));
        }
        // Many passkeys always report 0 (synced keys). Otherwise the counter must grow.
        if (($data->signCount !== 0 || $storedCount !== 0) && $data->signCount <= $storedCount) {
            throw new PasskeyException(esc_html(PasskeyException::COUNTER), esc_html('Signature counter did not increase.'));
        }
        return $data->signCount;
    }

    /**
     * @throws PasskeyException
     */
    private function checkClientData(string $json, string $type, string $challenge): void
    {
        $client = json_decode($json, true);
        if (! is_array($client) || ! is_string($client['type'] ?? null) || ! is_string($client['challenge'] ?? null) || ! is_string($client['origin'] ?? null)) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Invalid client data.'));
        }
        if ($client['type'] !== $type) {
            throw new PasskeyException(esc_html(PasskeyException::MALFORMED), esc_html('Wrong client data type.'));
        }
        if ($challenge === '' || ! hash_equals($challenge, $client['challenge'])) {
            throw new PasskeyException(esc_html(PasskeyException::CHALLENGE));
        }
        if (! in_array(strtolower($client['origin']), $this->origins, true) || ($client['crossOrigin'] ?? false) === true) {
            throw new PasskeyException(esc_html(PasskeyException::ORIGIN), esc_html('Origin ' . $client['origin'] . ' is not allowed.'));
        }
    }

    /**
     * @throws PasskeyException
     */
    private function checkAuthenticatorData(AuthenticatorData $data): void
    {
        if (! hash_equals(hash('sha256', $this->id, true), $data->rpIdHash)) {
            throw new PasskeyException(esc_html(PasskeyException::ORIGIN), esc_html('RP ID hash does not match.'));
        }
        if (! $data->has(AuthenticatorData::USER_PRESENT) || ! $data->has(AuthenticatorData::USER_VERIFIED)) {
            throw new PasskeyException(esc_html(PasskeyException::VERIFICATION));
        }
    }

    /**
     * Random challenge (32 bytes, base64url).
     */
    public static function challenge(): string
    {
        return Jwt::base64UrlEncode(random_bytes(32));
    }
}
