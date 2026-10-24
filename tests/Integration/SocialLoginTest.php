<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Auth\PendingLogin;
use Portier\Frontend\SocialScreens;
use Portier\Http\Response;
use Portier\OAuth\Identity;
use Portier\OAuth\StateStore;
use Portier\Plugin;
use Portier\Security\Crypto;
use Portier\Settings\Settings;
use Portier\Tests\Support\FakeIdp;
use Portier\Tests\Support\ScreenRequests;
use Portier\TwoFactor\Totp;
use WP_UnitTestCase;
use WP_User;

/**
 * Social login through the real screens: start, provider round trip, callback.
 *
 * @covers \Portier\Frontend\SocialScreens
 * @covers \Portier\OAuth\SocialAccounts
 * @covers \Portier\OAuth\StateStore
 * @covers \Portier\OAuth\ProviderConfig
 * @covers \Portier\Frontend\Controller
 */
final class SocialLoginTest extends WP_UnitTestCase
{
    use ScreenRequests;

    private FakeIdp $idp;
    private WP_User $user;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->jar = [];
        $this->forgetSession();
        reset_phpmailer_instance();
        $this->idp = new FakeIdp();
        $this->idp->register();
        $this->configure();
        $this->user = self::factory()->user->create_and_get(['user_login' => 'lena', 'user_email' => 'lena@example.org', 'role' => 'editor']);
    }

    public function tear_down(): void
    {
        $this->idp->unregister();
        $this->forgetSession();
        parent::tear_down();
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $settings
     */
    private function configure(array $provider = [], array $settings = []): void
    {
        update_option(Settings::OPTION, $settings + ['providers' => ['testidp' => $provider + [
            'enabled' => true,
            'client_id' => FakeIdp::CLIENT_ID,
            'client_secret' => Crypto::fromWordPress()->encrypt(FakeIdp::CLIENT_SECRET),
            'link' => 'verified',
            'signup' => false,
        ]]]);
    }

    /**
     * Clicks the provider button; returns the provider URL the browser is sent to.
     *
     * @param array<string, string> $query
     */
    private function start(array $query = []): string
    {
        $response = $this->send('GET', ['action' => 'oauth', 'provider' => 'testidp'] + $query);
        $this->assertSame(302, $response->status);
        $this->assertTrue($response->external);
        return (string) $response->redirect;
    }

    /**
     * The provider sends the browser back.
     *
     * @param array<string, mixed> $claims
     */
    private function returnFromProvider(string $authorizeUrl, array $claims): Response
    {
        $grant = $this->idp->authorize($authorizeUrl, $claims);
        return $this->send('GET', ['portier_provider' => 'testidp', 'state' => $grant['state'], 'code' => $grant['code']]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function socialLogin(array $claims = [], array $query = []): Response
    {
        return $this->returnFromProvider($this->start($query), $claims + ['sub' => 'idp-1', 'email' => 'lena@example.org', 'email_verified' => true, 'name' => 'Lena Idp']);
    }

    /**
     * Registration is a site option on single sites and a network option on multisite.
     */
    private function openRegistration(bool $open): void
    {
        if (is_multisite()) {
            update_site_option('registration', $open ? 'user' : 'none');
        } else {
            update_option('users_can_register', $open ? 1 : 0);
        }
    }

    private function assertNotice(string $notice, Response $response): void
    {
        $this->assertStringContainsString('notice=oauth-' . $notice, (string) $response->redirect);
        $this->assertSame(0, get_current_user_id(), 'Nobody is logged in.');
    }

    public function test_the_button_appears_only_for_usable_providers(): void
    {
        $this->assertStringContainsString('data-provider="testidp"', $this->send('GET')->body);

        $this->configure(['client_secret' => '']);
        $this->assertStringNotContainsString('data-provider="testidp"', $this->send('GET')->body);
        $this->configure(['enabled' => false]);
        $this->assertStringNotContainsString('data-provider="testidp"', $this->send('GET')->body);
    }

    public function test_start_binds_the_request_to_this_browser(): void
    {
        $url = $this->start();
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(home_url('/login/oauth/testidp/'), $query['redirect_uri']);
        $this->assertSame($query['state'], $this->jar[StateStore::COOKIE] ?? null, 'Same state in URL and cookie.');
    }

    public function test_a_linked_account_logs_in(): void
    {
        Plugin::instance()->socialAccounts()->link($this->user, new Identity('testidp', 'idp-1', 'other@example.org', true));
        $response = $this->socialLogin(['email' => 'changed@example.org']);
        $this->assertSame(admin_url(), $response->redirect);
        $this->assertSame($this->user->ID, get_current_user_id());
        $this->assertArrayNotHasKey(StateStore::COOKIE, $this->jar, 'The state cookie is removed.');
    }

    public function test_a_verified_email_links_the_existing_account(): void
    {
        $this->socialLogin();
        $this->assertSame($this->user->ID, get_current_user_id());
        $this->assertSame('idp-1', get_user_meta($this->user->ID, 'portier_sub_testidp', true));
    }

    public function test_unverified_emails_never_link(): void
    {
        $this->assertNotice('email', $this->socialLogin(['email_verified' => false]));
        $this->assertSame('', get_user_meta($this->user->ID, 'portier_sub_testidp', true));
    }

    public function test_linking_can_be_switched_off(): void
    {
        $this->configure(['link' => 'never']);
        $this->assertNotice('link-disabled', $this->socialLogin());
    }

    public function test_new_accounts_need_open_registration_and_permission(): void
    {
        $claims = ['sub' => 'idp-new', 'email' => 'new@example.org', 'email_verified' => true, 'given_name' => 'Neu', 'family_name' => 'Person', 'name' => 'Neu Person'];
        $this->assertNotice('no-account', $this->socialLogin($claims));

        $this->configure(['signup' => true]);
        $this->openRegistration(false);
        $this->assertNotice('no-account', $this->socialLogin($claims));

        $this->openRegistration(true);
        $response = $this->socialLogin($claims);
        $created = get_user_by('email', 'new@example.org');
        $this->assertInstanceOf(WP_User::class, $created);
        $this->assertSame($created->ID, get_current_user_id());
        $this->assertSame('Neu Person', $created->display_name);
        $this->assertSame('Person', $created->last_name);
        $this->assertSame([get_option('default_role')], $created->roles);
        // Subscribers land where core sends them (their profile), like after a password login.
        $this->assertSame(\Portier\Auth\RedirectResolver::forUser('', $created), $response->redirect);
    }

    public function test_administrators_cannot_log_in_socially(): void
    {
        $admin = self::factory()->user->create_and_get(['user_email' => 'boss@example.org', 'role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($admin->ID);
        }
        $this->assertNotice('blocked', $this->socialLogin(['sub' => 'boss', 'email' => 'boss@example.org']));
        $this->assertSame('', get_user_meta($admin->ID, 'portier_sub_testidp', true), 'Not linked either.');

        // Even an existing link does not help.
        Plugin::instance()->socialAccounts()->link($admin, new Identity('testidp', 'boss'));
        $this->assertNotice('blocked', $this->socialLogin(['sub' => 'boss', 'email' => 'boss@example.org']));

        update_option(Settings::OPTION, array_merge(get_option(Settings::OPTION), ['social_blocked_roles' => []]));
        $this->assertSame(admin_url(), $this->socialLogin(['sub' => 'boss', 'email' => 'boss@example.org'])->redirect);
    }

    public function test_callbacks_without_the_browser_cookie_are_rejected(): void
    {
        $url = $this->start();
        $this->jar = []; // Another browser (login CSRF attempt).
        $this->assertNotice('state', $this->returnFromProvider($url, ['sub' => 'idp-1', 'email' => 'lena@example.org', 'email_verified' => true]));
    }

    public function test_a_callback_works_only_once(): void
    {
        $url = $this->start();
        $grant = $this->idp->authorize($url, ['sub' => 'idp-1', 'email' => 'lena@example.org', 'email_verified' => true]);
        $cookie = $this->jar[StateStore::COOKIE];
        $this->send('GET', ['portier_provider' => 'testidp', 'state' => $grant['state'], 'code' => $grant['code']]);
        wp_set_current_user(0);

        $replay = $this->send('GET', ['portier_provider' => 'testidp', 'state' => $grant['state'], 'code' => $grant['code']], [], [StateStore::COOKIE => $cookie]);
        $this->assertNotice('state', $replay);
    }

    public function test_cancelling_at_the_provider(): void
    {
        $url = $this->start();
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        $response = $this->send('GET', ['portier_provider' => 'testidp', 'state' => $query['state'], 'error' => 'access_denied']);
        $this->assertNotice('cancelled', $response);
        $this->assertStringContainsString('provider=testidp', (string) $response->redirect);
    }

    public function test_the_error_parameter_survives_wordpress(): void
    {
        // WP::parse_request() removes $_GET['error']; the raw query string still has it.
        $request = SocialScreens::callbackRequest(new \Portier\Http\Request('GET', ['state' => 's']), 'testidp', 'error=access_denied&state=s');
        $this->assertSame('access_denied', $request->query('error'));
        $this->assertSame('testidp', $request->query('portier_provider'));

        $login = SocialScreens::callbackRequest(new \Portier\Http\Request('GET', []), '', 'error=x');
        $this->assertSame('', $login->query('error'), 'Only provider callbacks get it.');
        $tricky = SocialScreens::callbackRequest(new \Portier\Http\Request('GET', []), 'testidp', 'error=<script>');
        $this->assertSame('script', $tricky->query('error'));
    }

    public function test_provider_errors_and_forged_tokens(): void
    {
        $this->idp->tokenError = true;
        $this->assertNotice('provider', $this->socialLogin());

        $this->idp->tokenError = false;
        $this->idp->tamper = ['aud' => 'another-app'];
        $this->assertNotice('token', $this->socialLogin());
    }

    public function test_two_factor_authentication_still_applies(): void
    {
        update_option(Settings::OPTION, array_merge(get_option(Settings::OPTION), ['two_factor_mode' => 'optional']));
        $store = Plugin::instance()->twoFactor()->store();
        $store->enable($this->user->ID, $store->pendingSecret($this->user->ID), Totp::step(time()) - 5);

        $response = $this->socialLogin();
        $this->assertStringContainsString('action=2fa', (string) $response->redirect);
        $this->assertArrayHasKey(PendingLogin::COOKIE, $this->jar);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_redirect_and_remember_me_are_kept(): void
    {
        $remembered = null;
        add_filter('auth_cookie_expiration', static function (int $length, int $userId, bool $remember) use (&$remembered): int {
            $remembered = $remember;
            return $length;
        }, 10, 3);

        $response = $this->socialLogin([], ['redirect_to' => admin_url('edit.php'), 'rememberme' => '1']);
        $this->assertSame(admin_url('edit.php'), $response->redirect);
        $this->assertTrue($remembered);
    }

    public function test_connecting_from_the_profile(): void
    {
        $this->logInSession($this->user->ID);
        $this->assertStringContainsString('portier-social=error', (string) $this->send('GET', ['action' => 'oauth', 'provider' => 'testidp', 'link' => '1'])->redirect, 'Needs a nonce.');

        $url = $this->start(['link' => '1', '_wpnonce' => wp_create_nonce(SocialScreens::LINK_NONCE)]);
        $response = $this->returnFromProvider($url, ['sub' => 'idp-77', 'email' => 'private@example.net', 'email_verified' => false]);
        $this->assertStringContainsString('portier-social=linked', (string) $response->redirect);
        $this->assertSame('idp-77', get_user_meta($this->user->ID, 'portier_sub_testidp', true), 'Linking from the profile needs no matching email.');

        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertNotFalse($mail, 'The owner is told about the new way in.');
        $this->assertSame('lena@example.org', $mail->to[0][0]);
        $this->assertStringContainsString('Test IdP account was connected', $mail->body);
    }

    public function test_connecting_needs_a_recent_login(): void
    {
        $this->logInSession($this->user->ID, 20 * MINUTE_IN_SECONDS);
        $response = $this->send('GET', ['action' => 'oauth', 'provider' => 'testidp', 'link' => '1', '_wpnonce' => wp_create_nonce(SocialScreens::LINK_NONCE)]);
        $this->assertFalse($response->external, 'Not sent to the provider.');
        $this->assertStringContainsString('reauth=1', (string) $response->redirect);
        $this->assertStringContainsString(rawurlencode(admin_url('profile.php') . '#portier-social'), (string) $response->redirect);
    }

    public function test_a_provider_account_belongs_to_one_wordpress_account(): void
    {
        $other = self::factory()->user->create_and_get(['role' => 'author']);
        Plugin::instance()->socialAccounts()->link($other, new Identity('testidp', 'idp-77'));

        $this->logInSession($this->user->ID);
        $url = $this->start(['link' => '1', '_wpnonce' => wp_create_nonce(SocialScreens::LINK_NONCE)]);
        $response = $this->returnFromProvider($url, ['sub' => 'idp-77']);
        $this->assertStringContainsString('reason=linked-elsewhere', (string) $response->redirect);
        $this->assertSame('', get_user_meta($this->user->ID, 'portier_sub_testidp', true));
    }

    public function test_starts_are_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->start();
        }
        $this->assertStringContainsString('notice=oauth-limit', (string) $this->send('GET', ['action' => 'oauth', 'provider' => 'testidp'])->redirect);
    }

    public function test_unknown_or_disabled_providers_are_unavailable(): void
    {
        $this->assertStringContainsString('notice=oauth-unavailable', (string) $this->send('GET', ['action' => 'oauth', 'provider' => 'nope'])->redirect);
        $this->configure(['enabled' => false]);
        $this->assertStringContainsString('notice=oauth-unavailable', (string) $this->send('GET', ['action' => 'oauth', 'provider' => 'testidp'])->redirect);
    }

    /**
     * @dataProvider reasons
     */
    public function test_every_reason_has_a_readable_message(string $reason): void
    {
        $page = $this->send('GET', ['notice' => 'oauth-' . $reason, 'provider' => 'testidp']);
        $this->assertSame(200, $page->status);
        $this->assertMatchesRegularExpression('#<p class="portier-alert__text">[^<]*(Test IdP|Too many login attempts)[^<]*</p>#', $page->body);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reasons(): array
    {
        $reasons = ['cancelled', 'state', 'provider', 'token', 'email', 'no-account', 'link-disabled', 'linked-elsewhere', 'blocked', 'unavailable', 'limit'];
        return array_combine($reasons, array_map(static fn (string $reason): array => [$reason], $reasons));
    }
}
