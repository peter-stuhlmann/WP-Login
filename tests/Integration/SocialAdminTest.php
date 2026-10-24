<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Admin\SocialProfileSection;
use Portier\OAuth\Identity;
use Portier\OAuth\ProviderConfig;
use Portier\OAuth\Providers;
use Portier\Plugin;
use Portier\Routing\LoginRoute;
use Portier\Security\Crypto;
use Portier\Settings\Settings;
use Portier\Tests\Support\FakeIdp;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WP_User;

/**
 * Admin side of social login: REST settings (secrets!), provider config, profile section.
 *
 * @covers \Portier\Settings\RestController
 * @covers \Portier\Settings\Settings
 * @covers \Portier\OAuth\ProviderConfig
 * @covers \Portier\Admin\SocialProfileSection
 */
final class SocialAdminTest extends WP_UnitTestCase
{
    private FakeIdp $idp;
    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->idp = new FakeIdp();
        $this->idp->register();
        // The database is rolled back between tests without update hooks: forget cached settings.
        Plugin::instance()->settings()->flushCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        $this->idp->unregister();
        $_POST = [];
        parent::tear_down();
    }

    private function admin(): int
    {
        $user = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($user);
        }
        wp_set_current_user($user);
        return $user;
    }

    private function save(array $settings): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/portier/v1/settings');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['settings' => $settings]));
        return $this->server->dispatch($request);
    }

    private function stored(string $provider): array
    {
        return (array) (get_option(Settings::OPTION)['providers'][$provider] ?? []);
    }

    private function config(string $provider): ProviderConfig
    {
        $settings = new Settings();
        return ProviderConfig::for(Providers::get($provider), $settings, Crypto::fromWordPress());
    }

    public function test_secrets_are_encrypted_and_never_sent_back(): void
    {
        $this->admin();
        $response = $this->save(['providers' => ['testidp' => ['enabled' => true, 'client_id' => 'cid', 'client_secret' => 'top-secret']]]);
        $this->assertSame(200, $response->get_status());

        $stored = $this->stored('testidp');
        $this->assertStringStartsWith('pc1:', $stored['client_secret']);
        $this->assertStringNotContainsString('top-secret', (string) wp_json_encode(get_option(Settings::OPTION)));
        $this->assertSame('top-secret', $this->config('testidp')->clientSecret());

        $data = $response->get_data();
        $this->assertSame('', $data['settings']['providers']['testidp']['client_secret']);
        $this->assertStringNotContainsString('top-secret', (string) wp_json_encode($data));
        $this->assertStringNotContainsString($stored['client_secret'], (string) wp_json_encode($data), 'Not even encrypted.');
        $meta = array_column($data['meta']['providers'], null, 'id');
        $this->assertSame('option', $meta['testidp']['client_secret_source']);
        $this->assertSame(home_url('/login/oauth/testidp/'), $meta['testidp']['redirect_uri']);
    }

    public function test_an_empty_secret_keeps_the_stored_one(): void
    {
        $this->admin();
        $this->save(['providers' => ['testidp' => ['enabled' => true, 'client_id' => 'cid', 'client_secret' => 'first']]]);
        $this->save(['providers' => ['testidp' => ['enabled' => true, 'client_id' => 'cid2', 'client_secret' => '']]]);
        $this->assertSame('first', $this->config('testidp')->clientSecret());
        $this->assertSame('cid2', $this->config('testidp')->clientId());

        $this->save(['providers' => ['testidp' => ['enabled' => true, 'client_id' => 'cid2', 'client_secret' => 'second']]]);
        $this->assertSame('second', $this->config('testidp')->clientSecret());
    }

    public function test_enabling_needs_complete_credentials(): void
    {
        $this->admin();
        $response = $this->save(['providers' => ['testidp' => ['enabled' => true, 'client_id' => '', 'client_secret' => '']]]);
        $this->assertSame(400, $response->get_status());
        $errors = $response->get_data()['data']['errors'];
        $this->assertArrayHasKey('providers.testidp.client_id', $errors);
        $this->assertArrayHasKey('providers.testidp.client_secret', $errors);
        $this->assertSame([], $this->stored('testidp'));
    }

    public function test_unknown_providers_and_roles_are_rejected(): void
    {
        $this->admin();
        $this->save(['providers' => ['made-up' => ['enabled' => true]]]);
        $this->assertArrayNotHasKey('made-up', (array) get_option(Settings::OPTION)['providers']);

        $this->assertSame(400, $this->save(['social_blocked_roles' => ['ghost']])->get_status());
        $this->assertSame(200, $this->save(['social_blocked_roles' => ['administrator', 'editor']])->get_status());
    }

    public function test_provider_values_are_normalised(): void
    {
        $values = Settings::providerValues(['enabled' => 'yes', 'client_id' => " abc\n def ", 'link' => 'always', 'signup' => '1']);
        $this->assertTrue($values['enabled']);
        $this->assertSame('abcdef', $values['client_id']);
        $this->assertSame('verified', $values['link']);
        $this->assertTrue($values['signup']);
        $this->assertSame(['administrator'], Settings::defaults()['social_blocked_roles']);
    }

    public function test_constants_win_over_stored_credentials(): void
    {
        $this->assertNull(ProviderConfig::constant('consttest', 'CLIENT_ID'));
        define('PORTIER_CONSTTEST_CLIENT_ID', 'from-config');
        $this->assertSame('from-config', ProviderConfig::constant('consttest', 'CLIENT_ID'));
    }

    public function test_the_profile_offers_connecting_usable_providers(): void
    {
        update_option(Settings::OPTION, ['providers' => ['testidp' => ['enabled' => true, 'client_id' => 'cid', 'client_secret' => Crypto::fromWordPress()->encrypt('s')]]]);
        $user = self::factory()->user->create_and_get(['role' => 'editor']);
        wp_set_current_user($user->ID);

        $html = $this->render($user);
        $this->assertStringContainsString('Connect Test IdP', $html);
        $this->assertStringContainsString('action=oauth', $html);
        $this->assertStringContainsString('link=1', $html);
        $this->assertStringNotContainsString('Plain OAuth', $html, 'Unusable providers are not offered.');
    }

    private function testConnection(array $body): array
    {
        $request = new WP_REST_Request('POST', '/portier/v1/providers/testidp/test');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));
        $response = $this->server->dispatch($request);
        $this->assertSame(200, $response->get_status());
        return array_column($response->get_data()['checks'], 'status', 'key') + ['ok' => $response->get_data()['ok']];
    }

    public function test_test_connection_tells_whether_the_provider_accepts_the_credentials(): void
    {
        $this->admin();
        // Unsaved values from the form are tested.
        $result = $this->testConnection(['client_id' => FakeIdp::CLIENT_ID, 'client_secret' => FakeIdp::CLIENT_SECRET]);
        $this->assertSame(['reachable' => 'ok', 'credentials' => 'ok', 'redirect' => 'info', 'ok' => true], $result);
        $token = end($this->idp->requests);
        $this->assertSame('portier-connection-test', $token['args']['body']['code'], 'A code that cannot log anybody in.');

        $this->assertSame('fail', $this->testConnection(['client_id' => FakeIdp::CLIENT_ID, 'client_secret' => 'wrong'])['credentials']);
        $this->assertSame('skipped', $this->testConnection(['client_id' => '', 'client_secret' => ''])['credentials']);

        // An empty secret means the stored one.
        $this->save(['providers' => ['testidp' => ['client_id' => FakeIdp::CLIENT_ID, 'client_secret' => FakeIdp::CLIENT_SECRET]]]);
        $this->assertSame('ok', $this->testConnection(['client_id' => '', 'client_secret' => ''])['credentials']);
    }

    public function test_test_connection_reports_an_unreachable_provider_and_needs_permission(): void
    {
        $this->admin();
        delete_site_transient('portier_oidc_' . md5(FakeIdp::provider()->discovery));
        $down = static fn () => new \WP_Error('http_request_failed', 'down');
        add_filter('pre_http_request', $down, 20);
        $result = $this->testConnection(['client_id' => 'x', 'client_secret' => 'y']);
        remove_filter('pre_http_request', $down, 20);
        $this->assertSame('fail', $result['reachable']);
        $this->assertFalse($result['ok']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $request = new WP_REST_Request('POST', '/portier/v1/providers/testidp/test');
        $this->assertSame(403, $this->server->dispatch($request)->get_status());
    }

    public function test_connections_can_be_removed(): void
    {
        $user = self::factory()->user->create_and_get(['role' => 'editor']);
        Plugin::instance()->socialAccounts()->link($user, new Identity('testidp', 'sub-1', 'lena@example.org', true));
        wp_set_current_user($user->ID);
        $this->assertStringContainsString('lena@example.org', $this->render($user));

        $section = $this->section();
        $_POST = ['portier_social_unlink' => ['testidp'], '_portier_social_nonce' => 'forged'];
        $section->save($user->ID);
        $this->assertSame('sub-1', get_user_meta($user->ID, 'portier_sub_testidp', true), 'Nonce required.');

        $_POST['_portier_social_nonce'] = wp_create_nonce('portier-social-profile');
        $section->save($user->ID);
        $this->assertSame('', get_user_meta($user->ID, 'portier_sub_testidp', true));
    }

    public function test_others_cannot_remove_connections_without_permission(): void
    {
        $user = self::factory()->user->create_and_get(['role' => 'editor']);
        Plugin::instance()->socialAccounts()->link($user, new Identity('testidp', 'sub-1'));
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $_POST = ['portier_social_unlink' => ['testidp'], '_portier_social_nonce' => wp_create_nonce('portier-social-profile')];
        $this->section()->save($user->ID);
        $this->assertSame('sub-1', get_user_meta($user->ID, 'portier_sub_testidp', true));
    }

    public function test_blocked_roles_get_an_explanation_instead_of_buttons(): void
    {
        update_option(Settings::OPTION, ['providers' => ['testidp' => ['enabled' => true, 'client_id' => 'cid', 'client_secret' => Crypto::fromWordPress()->encrypt('s')]]]);
        $admin = get_userdata($this->admin());
        $html = $this->render($admin);
        $this->assertStringContainsString('For security reasons', $html);
        $this->assertStringNotContainsString('Connect Test IdP', $html);
    }

    private function section(): SocialProfileSection
    {
        return new SocialProfileSection(Plugin::instance()->socialAccounts(), new LoginRoute('login', true), new Settings());
    }

    private function render(WP_User $user): string
    {
        ob_start();
        $this->section()->render($user);
        return (string) ob_get_clean();
    }
}
