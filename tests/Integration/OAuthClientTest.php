<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\OAuth\Jwt;
use Portier\OAuth\OAuthClient;
use Portier\OAuth\OAuthException;
use Portier\OAuth\Provider;
use Portier\OAuth\Providers;
use Portier\Tests\Support\FakeIdp;
use WP_UnitTestCase;

/**
 * OAuth 2.0 / OpenID Connect client against an in-process provider.
 *
 * @covers \Portier\OAuth\OAuthClient
 * @covers \Portier\OAuth\Identity
 * @covers \Portier\OAuth\Providers
 * @covers \Portier\OAuth\Provider
 */
final class OAuthClientTest extends WP_UnitTestCase
{
    private FakeIdp $idp;
    private OAuthClient $client;

    public function set_up(): void
    {
        parent::set_up();
        $this->idp = new FakeIdp();
        $this->idp->register();
        $this->client = new OAuthClient();
    }

    public function tear_down(): void
    {
        $this->idp->unregister();
        parent::tear_down();
    }

    private function token(array $claims = [], array $header = []): string
    {
        return $this->idp->idToken(array_merge($this->idp->claims(['sub' => 'u-1'], 'n-1'), $claims), $header);
    }

    private function verify(string $token): array
    {
        return $this->client->verifyIdToken($token, FakeIdp::provider(), FakeIdp::CLIENT_ID, 'n-1');
    }

    private function assertRejected(string $token): void
    {
        try {
            $this->verify($token);
            $this->fail('Token was accepted.');
        } catch (OAuthException $exception) {
            $this->assertSame(OAuthException::TOKEN, $exception->reason);
        }
    }

    public function test_the_authorization_url_carries_state_nonce_and_pkce(): void
    {
        $url = $this->client->authorizationUrl(FakeIdp::provider(), 'cid', 'https://example.org/login/oauth/testidp/', 'st', 'no', 'verifier-123');
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(FakeIdp::BASE . '/authorize?', $url);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('https://example.org/login/oauth/testidp/', $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('st', $query['state']);
        $this->assertSame('no', $query['nonce']);
        $this->assertSame(OAuthClient::challenge('verifier-123'), $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function test_linkedin_gets_no_pkce_and_google_selects_an_account(): void
    {
        set_site_transient('portier_oidc_' . md5(Providers::get('linkedin')->discovery), ['issuer' => 'https://www.linkedin.com/oauth', 'authorization_endpoint' => 'https://www.linkedin.com/oauth/v2/authorization', 'token_endpoint' => 'https://www.linkedin.com/oauth/v2/accessToken', 'jwks_uri' => 'https://www.linkedin.com/oauth/openid/jwks']);
        set_site_transient('portier_oidc_' . md5(Providers::get('google')->discovery), ['issuer' => 'https://accounts.google.com', 'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token_endpoint' => 'https://oauth2.googleapis.com/token', 'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs']);

        $linkedin = $this->client->authorizationUrl(Providers::get('linkedin'), 'c', 'https://e.org/cb', 's', 'n', 'v');
        $google = $this->client->authorizationUrl(Providers::get('google'), 'c', 'https://e.org/cb', 's', 'n', 'v');
        $this->assertStringNotContainsString('code_challenge', $linkedin);
        $this->assertStringContainsString('code_challenge=', $google);
        $this->assertStringContainsString('prompt=select_account', $google);
    }

    public function test_discovery_is_cached(): void
    {
        $this->client->endpoints(FakeIdp::provider());
        $this->client->endpoints(FakeIdp::provider());
        $this->assertCount(1, $this->idp->requests);
    }

    public function test_insecure_discovery_documents_are_refused(): void
    {
        $this->expectException(OAuthException::class);
        $this->client->endpoints(new Provider(id: 'insecure', label: 'X', scopes: ['openid'], discovery: 'http://idp.example/.well-known/openid-configuration'));
    }

    public function test_a_valid_id_token_is_accepted(): void
    {
        $claims = $this->verify($this->token());
        $this->assertSame('u-1', $claims['sub']);
    }

    public function test_signature_must_match(): void
    {
        $this->idp->wrongKey = true;
        $this->assertRejected($this->token());
    }

    public function test_issuer_must_match(): void
    {
        $this->assertRejected($this->token(['iss' => 'https://evil.test']));
    }

    public function test_audience_must_contain_the_client(): void
    {
        $this->assertRejected($this->token(['aud' => 'someone-else']));
        $this->assertRejected($this->token(['aud' => ['someone-else', FakeIdp::CLIENT_ID]]), 'Several audiences need azp.');
        $this->assertSame('u-1', $this->verify($this->token(['aud' => ['someone-else', FakeIdp::CLIENT_ID], 'azp' => FakeIdp::CLIENT_ID]))['sub']);
    }

    public function test_expired_and_future_tokens_are_rejected(): void
    {
        $this->assertRejected($this->token(['exp' => time() - 600]));
        $this->assertRejected($this->token(['iat' => time() + 600]));
        $this->assertSame('u-1', $this->verify($this->token(['exp' => time() - 30]))['sub'], 'Small clock skew is fine.');
    }

    public function test_nonce_must_match(): void
    {
        $this->assertRejected($this->token(['nonce' => 'n-2']));
        $this->assertRejected($this->token(['nonce' => null]));
    }

    public function test_only_rs256_is_accepted(): void
    {
        $this->assertRejected($this->token([], ['alg' => 'HS256']));
        $this->assertRejected($this->token([], ['alg' => 'none']));
        $claims = Jwt::base64UrlEncode('{"alg":"none"}') . '.' . Jwt::base64UrlEncode((string) wp_json_encode($this->idp->claims(['sub' => 'u-1'], 'n-1'))) . '.';
        $this->assertRejected($claims);
    }

    public function test_unknown_key_ids_trigger_one_refresh(): void
    {
        $this->verify($this->token());
        $before = count($this->idp->requests);
        $this->assertRejected($this->token([], ['kid' => 'rotated-away']));
        $jwksRequests = array_filter(array_slice($this->idp->requests, $before), static fn ($request) => str_ends_with($request['url'], '/jwks'));
        $this->assertCount(1, $jwksRequests);
    }

    public function test_code_exchange_sends_secret_and_verifier(): void
    {
        $url = $this->client->authorizationUrl(FakeIdp::provider(), FakeIdp::CLIENT_ID, 'https://e.org/cb', 's', 'n-1', 'my-verifier');
        $code = $this->idp->authorize($url, ['sub' => 'u-9', 'email' => 'lena@example.org', 'email_verified' => true])['code'];

        $tokens = $this->client->exchange(FakeIdp::provider(), FakeIdp::CLIENT_ID, FakeIdp::CLIENT_SECRET, $code, 'https://e.org/cb', 'my-verifier');
        $identity = $this->client->identity(FakeIdp::provider(), $tokens, FakeIdp::CLIENT_ID, 'n-1');
        $this->assertSame('u-9', $identity->subject);
        $this->assertSame('lena@example.org', $identity->email);
        $this->assertTrue($identity->emailVerified);

        $this->expectException(OAuthException::class);
        $this->client->exchange(FakeIdp::provider(), FakeIdp::CLIENT_ID, FakeIdp::CLIENT_SECRET, $code, 'https://e.org/cb', 'my-verifier');
    }

    public function test_missing_emails_come_from_the_user_info_endpoint(): void
    {
        $login = function (array $userinfo) {
            $this->idp->userinfo = $userinfo;
            $url = $this->client->authorizationUrl(FakeIdp::provider(), FakeIdp::CLIENT_ID, 'https://e.org/cb', 's', 'n-1', 'v');
            $code = $this->idp->authorize($url, ['sub' => 'u-5'])['code'];
            $tokens = $this->client->exchange(FakeIdp::provider(), FakeIdp::CLIENT_ID, FakeIdp::CLIENT_SECRET, $code, 'https://e.org/cb', 'v');
            return $this->client->identity(FakeIdp::provider(), $tokens, FakeIdp::CLIENT_ID, 'n-1');
        };

        $identity = $login(['sub' => 'u-5', 'email' => 'five@example.org', 'email_verified' => true]);
        $this->assertSame('five@example.org', $identity->email);

        $identity = $login(['sub' => 'someone-else', 'email' => 'evil@example.org', 'email_verified' => true]);
        $this->assertSame('', $identity->email, 'Details about another person are ignored.');

        $identity = $login([]);
        $this->assertSame('u-5', $identity->subject, 'An unreachable user info endpoint does not break the login.');
    }

    public function test_a_wrong_pkce_verifier_fails(): void
    {
        $url = $this->client->authorizationUrl(FakeIdp::provider(), FakeIdp::CLIENT_ID, 'https://e.org/cb', 's', 'n-1', 'right');
        $code = $this->idp->authorize($url, ['sub' => 'u-9'])['code'];
        $this->expectException(OAuthException::class);
        $this->client->exchange(FakeIdp::provider(), FakeIdp::CLIENT_ID, FakeIdp::CLIENT_SECRET, $code, 'https://e.org/cb', 'wrong');
    }

    public function test_plain_oauth_providers_use_the_user_endpoint(): void
    {
        $provider = Providers::get('plain');
        $url = $this->client->authorizationUrl($provider, FakeIdp::CLIENT_ID, 'https://e.org/cb', 's', 'n', 'v');
        $this->assertStringNotContainsString('nonce=', $url);
        $code = $this->idp->authorize($url, ['id' => '42', 'email' => 'max@example.org', 'verified' => true, 'name' => 'Max'])['code'];
        $tokens = $this->client->exchange($provider, FakeIdp::CLIENT_ID, FakeIdp::CLIENT_SECRET, $code, 'https://e.org/cb', 'v');
        $identity = $this->client->identity($provider, $tokens, FakeIdp::CLIENT_ID, 'n');
        $this->assertSame('42', $identity->subject);
        $this->assertSame('Max', $identity->name);

        $last = end($this->idp->requests);
        $this->assertSame('Bearer ' . $tokens['access_token'], $last['args']['headers']['Authorization']);
    }

    public function test_discord_users_are_mapped(): void
    {
        $identity = (Providers::get('discord')->mapUser)(['id' => '80351110224678912', 'username' => 'nelly', 'global_name' => 'Nelly', 'email' => 'nelly@example.org', 'verified' => true]);
        $this->assertSame('80351110224678912', $identity->subject);
        $this->assertSame('Nelly', $identity->name);
        $this->assertTrue($identity->emailVerified);
    }
}
