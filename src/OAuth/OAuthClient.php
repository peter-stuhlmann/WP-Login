<?php

declare(strict_types=1);

namespace Portier\OAuth;

/**
 * Authorization code flow (RFC 6749) with PKCE (RFC 7636) and OpenID Connect ID token
 * validation (OIDC Core 3.1.3.7).
 *
 * All HTTP goes through the WordPress HTTP API (TLS verification on, short timeouts), so it can
 * be mocked in tests with the `pre_http_request` filter.
 */
final class OAuthClient
{
    /** Allowed clock difference between WordPress and the provider, in seconds. */
    private const LEEWAY = 120;
    private const TIMEOUT = 10;

    /**
     * @param (\Closure(): int)|null $now Clock (tests).
     */
    public function __construct(private readonly ?\Closure $now = null)
    {
    }

    /**
     * Random PKCE code verifier (64 characters, RFC 7636 section 4.1).
     */
    public static function verifier(): string
    {
        return Jwt::base64UrlEncode(random_bytes(48));
    }

    /**
     * S256 code challenge for a verifier.
     */
    public static function challenge(string $verifier): string
    {
        return Jwt::base64UrlEncode(hash('sha256', $verifier, true));
    }

    /**
     * Endpoints of a provider – from its discovery document (cached for a day) or its definition.
     *
     * @return array{authorize: string, token: string, userinfo: string, jwks: string, issuer: string}
     * @throws OAuthException When the discovery document cannot be loaded.
     */
    public function endpoints(Provider $provider): array
    {
        if (! $provider->isOpenIdConnect()) {
            return ['authorize' => $provider->authorizeUrl, 'token' => $provider->tokenUrl, 'userinfo' => $provider->userinfoUrl, 'jwks' => '', 'issuer' => ''];
        }
        $cacheKey = 'portier_oidc_' . md5($provider->discovery);
        $cached = get_site_transient($cacheKey);
        $document = is_array($cached) ? $cached : $this->getJson($provider->discovery);
        $endpoints = [
            'authorize' => (string) ($document['authorization_endpoint'] ?? ''),
            'token' => (string) ($document['token_endpoint'] ?? ''),
            'userinfo' => (string) ($document['userinfo_endpoint'] ?? ''),
            'jwks' => (string) ($document['jwks_uri'] ?? ''),
            'issuer' => (string) ($document['issuer'] ?? ''),
        ];
        foreach (['authorize', 'token', 'jwks'] as $required) {
            if (! self::isSafeUrl($endpoints[$required])) {
                throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html("Discovery document without a usable {$required} endpoint."));
            }
        }
        if ($endpoints['issuer'] === '') {
            throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html('Discovery document without issuer.'));
        }
        if (! is_array($cached)) {
            // The raw document is cached, so the same validation runs on cached data.
            set_site_transient($cacheKey, $document, DAY_IN_SECONDS);
        }
        return $endpoints;
    }

    /**
     * URL that sends the browser to the provider.
     */
    public function authorizationUrl(Provider $provider, string $clientId, string $redirectUri, string $state, string $nonce, string $verifier): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $provider->scopes),
            'state' => $state,
        ];
        if ($provider->isOpenIdConnect()) {
            $params['nonce'] = $nonce;
        }
        if ($provider->pkce) {
            $params['code_challenge'] = self::challenge($verifier);
            $params['code_challenge_method'] = 'S256';
        }
        $endpoint = $this->endpoints($provider)['authorize'];
        $separator = str_contains($endpoint, '?') ? '&' : '?';
        return $endpoint . $separator . http_build_query($params + $provider->authorizeParams, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the authorization code for tokens (back channel, client secret in the body).
     *
     * @return array<string, mixed> Token response.
     * @throws OAuthException
     */
    public function exchange(Provider $provider, string $clientId, string $clientSecret, string $code, string $redirectUri, string $verifier): array
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
        if ($provider->pkce) {
            $body['code_verifier'] = $verifier;
        }
        $response = wp_remote_post($this->endpoints($provider)['token'], [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
            'body' => $body,
        ]);
        $tokens = $this->decodeJson($response);
        if (! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '') {
            throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html('Token response without access token: ' . (string) ($tokens['error'] ?? 'unknown')));
        }
        return $tokens;
    }

    /**
     * "Test connection" in the admin: asks the token endpoint with a code that cannot be valid.
     * Real providers check the client first, so the answer tells whether ID and secret are
     * known – "invalid_client" means they are wrong, any other OAuth error (usually
     * "invalid_grant") means they were accepted and only the code is wrong. Nobody is logged
     * in and nothing is stored.
     *
     * @return 'accepted'|'rejected'|'unknown'
     * @throws OAuthException When the provider cannot be reached.
     */
    public function checkClient(Provider $provider, string $clientId, string $clientSecret, string $redirectUri): string
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => 'portier-connection-test',
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
        if ($provider->pkce) {
            $body['code_verifier'] = self::verifier();
        }
        $response = wp_remote_post($this->endpoints($provider)['token'], [
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
            'body' => $body,
        ]);
        if ($response instanceof \WP_Error) {
            throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html('HTTP error: ' . $response->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $error = is_array($data) && is_string($data['error'] ?? null) ? strtolower($data['error']) : '';
        if (in_array($error, ['invalid_client', 'unauthorized_client'], true) || ($error === '' && $status === 401)) {
            return 'rejected';
        }
        return $error !== '' ? 'accepted' : 'unknown';
    }

    /**
     * The verified identity behind a token response.
     *
     * @param array<string, mixed> $tokens
     * @throws OAuthException
     */
    public function identity(Provider $provider, array $tokens, string $clientId, string $nonce): Identity
    {
        $accessToken = (string) $tokens['access_token'];
        if (! $provider->isOpenIdConnect()) {
            $mapUser = $provider->mapUser;
            if ($mapUser === null) {
                throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html('OAuth 2.0 provider without mapUser.'));
            }
            return $mapUser($this->getJson($provider->userinfoUrl, $accessToken));
        }

        if (! is_string($tokens['id_token'] ?? null)) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('Token response without ID token.'));
        }
        $claims = $this->verifyIdToken($tokens['id_token'], $provider, $clientId, $nonce);

        // Some providers keep e-mail details out of the ID token; ask the user info endpoint.
        $userinfo = $this->endpoints($provider)['userinfo'];
        if (empty($claims['email']) && $userinfo !== '') {
            try {
                $extra = $this->getJson($userinfo, $accessToken);
                // Only trust details about the same person as the verified ID token.
                if ((string) ($extra['sub'] ?? '') === (string) $claims['sub']) {
                    $claims += $extra;
                }
            } catch (OAuthException) {
                // Optional details – the verified ID token alone identifies the person.
            }
        }
        return Identity::fromClaims($provider->id, $claims);
    }

    /**
     * Validates an ID token and returns its claims.
     *
     * @return array<string, mixed>
     * @throws OAuthException
     */
    public function verifyIdToken(string $jwt, Provider $provider, string $clientId, string $nonce): array
    {
        $token = Jwt::decode($jwt);
        $header = $token['header'];
        $claims = $token['claims'];

        if (($header['alg'] ?? '') !== 'RS256') {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('Unsupported ID token algorithm.'));
        }
        $endpoints = $this->endpoints($provider);
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : '';
        $key = $this->findKey($this->keys($endpoints['jwks']), $kid);
        if ($key === null) {
            // Keys rotate: fetch once more before giving up.
            $key = $this->findKey($this->keys($endpoints['jwks'], true), $kid);
        }
        if ($key === null || ! Jwt::verifyRs256($token['input'], $token['signature'], $key)) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token signature is invalid.'));
        }

        $issuers = array_merge([$endpoints['issuer']], $provider->issuers);
        if (! in_array($claims['iss'] ?? null, $issuers, true)) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token issuer mismatch.'));
        }
        $audience = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (! in_array($clientId, $audience, true)) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token audience mismatch.'));
        }
        if (count($audience) > 1 && ($claims['azp'] ?? null) !== $clientId) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token authorized party mismatch.'));
        }
        $now = $this->now();
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - self::LEEWAY) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token expired.'));
        }
        if (is_numeric($claims['iat'] ?? null) && (int) $claims['iat'] > $now + self::LEEWAY) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token issued in the future.'));
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token nonce mismatch.'));
        }
        if (! is_scalar($claims['sub'] ?? null) || (string) $claims['sub'] === '') {
            throw new OAuthException(esc_html(OAuthException::TOKEN), esc_html('ID token without subject.'));
        }
        return $claims;
    }

    /**
     * Only HTTPS – plain HTTP is accepted for loopback hosts (local development and tests).
     */
    public static function isSafeUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || ! isset($parts['scheme'])) {
            return false;
        }
        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keys(string $jwksUri, bool $refresh = false): array
    {
        $cacheKey = 'portier_jwks_' . md5($jwksUri);
        $cached = $refresh ? false : get_site_transient($cacheKey);
        $document = is_array($cached) ? $cached : $this->getJson($jwksUri);
        if (! is_array($cached)) {
            set_site_transient($cacheKey, $document, 6 * HOUR_IN_SECONDS);
        }
        $keys = [];
        foreach ((array) ($document['keys'] ?? []) as $key) {
            if (is_array($key)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * @param list<array<string, mixed>> $keys
     * @return array<string, mixed>|null
     */
    private function findKey(array $keys, string $kid): ?array
    {
        $candidates = array_values(array_filter(
            $keys,
            static fn (array $key): bool => ($key['kty'] ?? '') === 'RSA'
                && ($key['use'] ?? 'sig') === 'sig'
                && ($kid === '' || ($key['kid'] ?? '') === $kid)
        ));
        // Without a key id, only an unambiguous single key may be used.
        return $kid === '' && count($candidates) !== 1 ? null : ($candidates[0] ?? null);
    }

    /**
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function getJson(string $url, string $bearer = ''): array
    {
        if (! self::isSafeUrl($url)) {
            throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html('Refusing a non-HTTPS provider URL.'));
        }
        $headers = ['Accept' => 'application/json'];
        if ($bearer !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
        return $this->decodeJson(wp_remote_get($url, ['timeout' => self::TIMEOUT, 'redirection' => 0, 'headers' => $headers]));
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function decodeJson($response): array
    {
        if ($response instanceof \WP_Error) {
            throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html('HTTP error: ' . $response->get_error_message()));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || ! is_array($data)) {
            throw new OAuthException(esc_html(OAuthException::PROVIDER), esc_html("Provider answered with HTTP {$status}."));
        }
        return $data;
    }

    private function now(): int
    {
        return $this->now !== null ? ($this->now)() : time();
    }
}
