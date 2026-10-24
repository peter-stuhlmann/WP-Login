<?php

declare(strict_types=1);

namespace Portier\Tests\Support;

use Portier\OAuth\Identity;
use Portier\OAuth\Jwt;
use Portier\OAuth\Provider;

/**
 * An OpenID Connect provider inside the test process: answers the plugin's HTTP requests
 * (discovery, JWKS, token, user info) through the `pre_http_request` filter and signs real RS256
 * ID tokens with a fixed test key (tests/Support/fixtures – test only, never used elsewhere).
 */
final class FakeIdp
{
    public const BASE = 'https://idp.test';
    public const CLIENT_ID = 'test-client';
    public const CLIENT_SECRET = 'test-secret';

    /** @var array<string, array{claims: array<string, mixed>, nonce: string, challenge: string}> */
    private array $codes = [];

    /** @var list<array{url: string, args: array<string, mixed>}> Requests seen (for assertions). */
    public array $requests = [];

    /** Overrides for the next ID token (e.g. ['iss' => 'evil']). @var array<string, mixed> */
    public array $tamper = [];

    /** Use another key to sign (signature must fail). */
    public bool $wrongKey = false;

    /** Answer the token request with an HTTP error. */
    public bool $tokenError = false;

    /** Response of the OIDC user info endpoint (empty: HTTP 404). @var array<string, mixed> */
    public array $userinfo = [];

    private \OpenSSLAsymmetricKey $key;
    private \OpenSSLAsymmetricKey $otherKey;

    public function __construct()
    {
        $this->key = self::loadKey('idp-key.pem');
        $this->otherKey = self::loadKey('idp-key-other.pem');
    }

    public function register(): void
    {
        add_filter('pre_http_request', [$this, 'handle'], 10, 3);
        add_filter('portier_oauth_providers', [$this, 'addProvider']);
        \Portier\OAuth\Providers::reset();
    }

    public function unregister(): void
    {
        remove_filter('pre_http_request', [$this, 'handle']);
        remove_filter('portier_oauth_providers', [$this, 'addProvider']);
        \Portier\OAuth\Providers::reset();
    }

    /**
     * @param list<Provider> $providers
     * @return list<Provider>
     */
    public function addProvider(array $providers): array
    {
        $providers[] = self::provider();
        $providers[] = new Provider(
            id: 'plain',
            label: 'Plain OAuth',
            scopes: ['identify'],
            authorizeUrl: self::BASE . '/plain/authorize',
            tokenUrl: self::BASE . '/token',
            userinfoUrl: self::BASE . '/plain/me',
            mapUser: static fn (array $user): Identity => new Identity('plain', (string) ($user['id'] ?? ''), (string) ($user['email'] ?? ''), (bool) ($user['verified'] ?? false), (string) ($user['name'] ?? '')),
        );
        return $providers;
    }

    public static function provider(): Provider
    {
        return new Provider(id: 'testidp', label: 'Test IdP', scopes: ['openid', 'email', 'profile'], discovery: self::BASE . '/.well-known/openid-configuration');
    }

    /**
     * What the provider does when the person agrees: returns an authorization code.
     *
     * @param string               $authorizeUrl The URL Portier sent the browser to.
     * @param array<string, mixed> $claims       Identity claims (sub, email, email_verified, …).
     * @return array<string, string> Query of the authorization URL (state, nonce, …) plus `code`.
     */
    public function authorize(string $authorizeUrl, array $claims): array
    {
        $query = [];
        parse_str((string) wp_parse_url($authorizeUrl, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        $code = bin2hex(random_bytes(8));
        $this->codes[$code] = ['claims' => $claims, 'nonce' => (string) ($query['nonce'] ?? ''), 'challenge' => (string) ($query['code_challenge'] ?? '')];
        return $query + ['code' => $code];
    }

    /**
     * A signed ID token.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    public function idToken(array $claims, array $header = []): string
    {
        $header += ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-key'];
        $input = Jwt::base64UrlEncode((string) wp_json_encode($header)) . '.' . Jwt::base64UrlEncode((string) wp_json_encode($claims));
        openssl_sign($input, $signature, $this->wrongKey ? $this->otherKey : $this->key, OPENSSL_ALGO_SHA256);
        return $input . '.' . Jwt::base64UrlEncode((string) $signature);
    }

    /**
     * Standard claims for a valid token.
     *
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    public function claims(array $claims, string $nonce): array
    {
        return $claims + [
            'iss' => self::BASE,
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => $nonce,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jwk(): array
    {
        $details = openssl_pkey_get_details($this->key);
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'test-key',
            'n' => Jwt::base64UrlEncode($details['rsa']['n']),
            'e' => Jwt::base64UrlEncode($details['rsa']['e']),
        ];
    }

    /**
     * @param false|array<string, mixed>|\WP_Error $preempt
     * @param array<string, mixed>                 $args
     * @return false|array<string, mixed>
     */
    public function handle($preempt, array $args, string $url)
    {
        if (! str_starts_with($url, self::BASE)) {
            return $preempt;
        }
        $this->requests[] = ['url' => $url, 'args' => $args];
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return match ($path) {
            '/.well-known/openid-configuration' => self::json([
                'issuer' => self::BASE,
                'authorization_endpoint' => self::BASE . '/authorize',
                'token_endpoint' => self::BASE . '/token',
                'userinfo_endpoint' => self::BASE . '/userinfo',
                'jwks_uri' => self::BASE . '/jwks',
            ]),
            '/jwks' => self::json(['keys' => [$this->jwk()]]),
            '/token' => $this->token((array) ($args['body'] ?? [])),
            '/plain/me' => self::json($this->lastClaims()),
            '/userinfo' => $this->userinfo !== [] ? self::json($this->userinfo) : self::json(['error' => 'not_found'], 404),
            default => self::json(['error' => 'not_found'], 404),
        };
    }

    /** @var array<string, mixed> */
    private array $lastClaims = [];

    /**
     * @return array<string, mixed>
     */
    private function lastClaims(): array
    {
        return $this->lastClaims;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function token(array $body): array
    {
        $code = (string) ($body['code'] ?? '');
        // Like real providers: the client is checked before the code.
        if (($body['client_secret'] ?? '') !== self::CLIENT_SECRET) {
            return self::json(['error' => 'invalid_client'], 401);
        }
        if ($this->tokenError || ! isset($this->codes[$code])) {
            return self::json(['error' => 'invalid_grant'], 400);
        }
        $grant = $this->codes[$code];
        unset($this->codes[$code]); // Codes work once.
        if ($grant['challenge'] !== '' && Jwt::base64UrlEncode(hash('sha256', (string) ($body['code_verifier'] ?? ''), true)) !== $grant['challenge']) {
            return self::json(['error' => 'invalid_grant', 'error_description' => 'PKCE'], 400);
        }
        $this->lastClaims = $grant['claims'];
        return self::json([
            'access_token' => 'at-' . $code,
            'token_type' => 'Bearer',
            'id_token' => $this->idToken(array_merge($this->claims($grant['claims'], $grant['nonce']), $this->tamper)),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function json(array $data, int $status = 200): array
    {
        return [
            'headers' => ['content-type' => 'application/json'],
            'body' => (string) wp_json_encode($data),
            'response' => ['code' => $status, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ];
    }

    private static function loadKey(string $file): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private((string) file_get_contents(__DIR__ . '/fixtures/' . $file));
        if ($key === false) {
            throw new \RuntimeException('Test key missing: ' . $file);
        }
        return $key;
    }
}
