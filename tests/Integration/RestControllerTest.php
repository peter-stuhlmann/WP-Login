<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Plugin;
use Portier\Settings\Settings;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * @covers \Portier\Settings\RestController
 */
final class RestControllerTest extends WP_UnitTestCase
{
    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        $this->server = $wp_rest_server;
        do_action('rest_api_init');
        // The settings object outlives a test; its database rows do not.
        Plugin::instance()->settings()->flushCache();
    }

    public function tear_down(): void
    {
        $this->remove_added_uploads();
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function request(string $method, array $body = []): \WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/portier/v1/settings');
        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }
        return $this->server->dispatch($request);
    }

    public function test_guests_and_subscribers_are_denied(): void
    {
        $this->assertSame(401, $this->request('GET')->get_status());
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->request('GET')->get_status());
        $this->assertSame(403, $this->request('POST', ['settings' => ['enabled' => false]])->get_status());
    }

    public function test_administrators_can_read_settings(): void
    {
        $this->becomeAdministrator();
        $response = $this->request('GET');
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('login', $data['settings']['slug']);
        $this->assertArrayHasKey('login_url', $data['meta']);
        $this->assertArrayHasKey('roles', $data['registration']);
    }

    public function test_valid_settings_are_saved(): void
    {
        $this->becomeAdministrator();
        $response = $this->request('POST', ['settings' => ['slug' => 'anmelden', 'min_password_length' => 14]]);
        $this->assertSame(200, $response->get_status());
        $stored = get_option(Settings::OPTION);
        $this->assertSame('anmelden', $stored['slug']);
        $this->assertSame(14, $stored['min_password_length']);
    }

    public function test_invalid_settings_return_translated_field_errors(): void
    {
        $this->becomeAdministrator();
        $response = $this->request('POST', ['settings' => ['slug' => 'wp-admin']]);
        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('slug', $response->get_data()['data']['errors']);
    }

    /**
     * @group ms-excluded
     */
    public function test_registration_maps_to_core_options(): void
    {
        $this->becomeAdministrator();
        $this->request('POST', ['registration' => ['open' => true, 'default_role' => 'author']]);
        $this->assertSame('1', (string) get_option('users_can_register'));
        $this->assertSame('author', get_option('default_role'));
    }

    /**
     * @group ms-excluded
     */
    public function test_new_accounts_can_never_become_administrators(): void
    {
        $this->becomeAdministrator();
        $response = $this->request('POST', ['registration' => ['default_role' => 'administrator']]);
        $this->assertSame(400, $response->get_status());
        $this->assertNotSame('administrator', get_option('default_role'));
    }

    public function test_two_factor_roles_must_exist(): void
    {
        $this->becomeAdministrator();
        $response = $this->request('POST', ['settings' => ['two_factor_roles' => ['administrator', 'ghost']]]);
        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('two_factor_roles', $response->get_data()['data']['errors']);
    }

    public function test_required_two_factor_needs_at_least_one_role(): void
    {
        $this->becomeAdministrator();
        $response = $this->request('POST', ['settings' => ['two_factor_mode' => 'required', 'two_factor_roles' => []]]);
        $this->assertSame(400, $response->get_status());

        $ok = $this->request('POST', ['settings' => ['two_factor_mode' => 'required', 'two_factor_roles' => ['editor', 'administrator']]]);
        $this->assertSame(200, $ok->get_status());
        $this->assertSame(['editor', 'administrator'], get_option(Settings::OPTION)['two_factor_roles']);
    }

    /**
     * @group ms-required
     */
    public function test_registration_is_read_only_on_multisite(): void
    {
        $this->becomeAdministrator();
        $data = $this->request('GET')->get_data();
        $this->assertFalse($data['registration']['editable']);
    }

    /**
     * @group ms-required
     */
    public function test_on_multisite_only_network_administrators_change_security_settings(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame(Settings::NETWORK_KEYS, $this->request('GET')->get_data()['meta']['network_locked']);

        $denied = $this->request('POST', ['settings' => ['two_factor_mode' => 'off', 'social_blocked_roles' => [], 'template' => 'pure']]);
        $this->assertSame(400, $denied->get_status());
        $this->assertSame(['two_factor_mode', 'social_blocked_roles'], array_keys($denied->get_data()['data']['errors']));
        $this->assertSame(200, $this->request('POST', ['settings' => ['two_factor_mode' => 'optional', 'template' => 'pure']])->get_status(), 'Unchanged values and other settings are fine.');

        $this->becomeAdministrator();
        $this->assertSame([], $this->request('GET')->get_data()['meta']['network_locked']);
        $this->assertSame(200, $this->request('POST', ['settings' => ['two_factor_mode' => 'off']])->get_status());
    }

    public function test_custom_css_needs_unfiltered_html_and_keeps_versions(): void
    {
        $this->becomeAdministrator();
        \Portier\Plugin::instance()->settings()->flushCache();
        $grant = static function (array $caps): array {
            $caps['unfiltered_html'] = true;
            return $caps;
        };
        add_filter('user_has_cap', $grant);
        $this->assertSame(200, $this->request('POST', ['settings' => ['custom_css' => 'a{color:red}']])->get_status());
        $this->assertSame(200, $this->request('POST', ['settings' => ['custom_css' => 'a{color:blue}']])->get_status());
        $data = $this->request('GET')->get_data();
        $this->assertTrue($data['meta']['can_edit_css']);
        $this->assertSame('a{color:red}', $data['meta']['css_revisions'][0]['css']);
        remove_filter('user_has_cap', $grant);

        // map_meta_cap also applies to super admins on multisite (user_has_cap does not).
        $deny = static fn (array $caps, string $cap): array => $cap === 'unfiltered_html' ? ['do_not_allow'] : $caps;
        add_filter('map_meta_cap', $deny, 10, 2);
        $response = $this->request('POST', ['settings' => ['custom_css' => 'a{color:green}']]);
        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('custom_css', $response->get_data()['data']['errors']);
        $this->assertSame(200, $this->request('POST', ['settings' => ['custom_css' => 'a{color:blue}', 'template' => 'bold']])->get_status(), 'Unchanged CSS does not block other settings.');
        remove_filter('map_meta_cap', $deny, 10);
        $this->assertSame('a{color:blue}', get_option(Settings::OPTION)['custom_css']);
    }

    public function test_the_design_editor_gets_templates_fonts_and_a_preview_url(): void
    {
        $this->becomeAdministrator();
        $meta = $this->request('GET')->get_data()['meta'];
        $this->assertCount(8, $meta['templates']);
        $this->assertArrayHasKey('instrument-serif', $meta['fonts']);
        $this->assertStringContainsString('portier_preview=', $meta['preview_url']);
    }

    public function test_background_images_must_be_images(): void
    {
        $this->becomeAdministrator();
        $post = self::factory()->post->create();
        $response = $this->request('POST', ['settings' => ['bg_image' => $post]]);
        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('bg_image', $response->get_data()['data']['errors']);

        $image = self::factory()->attachment->create_upload_object(PORTIER_DIR . '/tests/e2e/fixtures/background.jpg');
        $response = $this->request('POST', ['settings' => ['bg_image' => $image]]);
        $this->assertSame(200, $response->get_status());
        $meta = $response->get_data()['meta'];
        $this->assertSame($image, $meta['images']['bg_image']['id']);
        $this->assertStringEndsWith('.jpg', $meta['images']['bg_image']['filename']);
        $this->assertSame(\Portier\Settings\Settings::PREVIEW_KEYS, $meta['preview_keys'], 'One list for PHP and JS.');
        $this->assertContains('bg_overlay', $meta['preview_keys']);
    }

    private function becomeAdministrator(): void
    {
        $user = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($user);
        }
        wp_set_current_user($user);
    }
}
