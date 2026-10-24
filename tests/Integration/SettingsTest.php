<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Settings\Settings;
use WP_UnitTestCase;

/**
 * @covers \Portier\Settings\Settings
 */
final class SettingsTest extends WP_UnitTestCase
{
    public function test_defaults_apply_when_nothing_is_stored(): void
    {
        delete_option(Settings::OPTION);
        $settings = new Settings();
        $this->assertTrue($settings->bool('enabled'));
        $this->assertSame('login', $settings->string('slug'));
        $this->assertSame(12, $settings->int('min_password_length'));
    }

    public function test_slugs_are_normalised(): void
    {
        $result = (new Settings())->sanitize(['slug' => ' /Mein Login/ ']);
        $this->assertSame('mein-login', $result['values']['slug']);
        $this->assertSame([], $result['errors']);
    }

    public function test_reserved_and_malformed_slugs_are_rejected(): void
    {
        $settings = new Settings();
        $this->assertSame('slug_reserved', $settings->sanitize(['slug' => 'wp-admin'])['errors']['slug']['code']);
        $this->assertSame('slug_format', $settings->sanitize(['slug' => 'a'])['errors']['slug']['code']);
        $this->assertSame('slug_format', $settings->sanitize(['slug' => '-login'])['errors']['slug']['code']);
    }

    public function test_numbers_outside_their_range_are_reported(): void
    {
        $result = (new Settings())->sanitize(['min_password_length' => 4, 'rate_limit_attempts' => 'many']);
        $this->assertSame(['code' => 'out_of_range', 'min' => 8, 'max' => 64], $result['errors']['min_password_length']);
        $this->assertArrayHasKey('rate_limit_attempts', $result['errors']);
        $this->assertSame(12, $result['values']['min_password_length'], 'Invalid values fall back to the default.');
    }

    public function test_unknown_keys_and_invalid_choices_are_dropped(): void
    {
        $result = (new Settings())->sanitize(['evil' => 'x', 'core_login_behavior' => 'explode', 'trusted_proxy_header' => 'HTTP_EVIL']);
        $this->assertArrayNotHasKey('evil', $result['values']);
        $this->assertSame('redirect', $result['values']['core_login_behavior']);
        $this->assertSame('', $result['values']['trusted_proxy_header']);
    }

    public function test_two_factor_and_magic_link_options_are_sanitised(): void
    {
        $result = (new Settings())->sanitize([
            'two_factor_mode' => 'sometimes',
            'two_factor_roles' => ['Editor', 'editor', '<b>admin</b>', ''],
            'trust_device_days' => 365,
            'magic_link' => '1',
            'magic_link_minutes' => 30,
        ]);
        $this->assertSame('optional', $result['values']['two_factor_mode']);
        $this->assertSame(['editor', 'badminb'], $result['values']['two_factor_roles']);
        $this->assertArrayHasKey('trust_device_days', $result['errors']);
        $this->assertTrue($result['values']['magic_link']);
        $this->assertSame(30, $result['values']['magic_link_minutes']);
    }

    public function test_update_persists_valid_values_only(): void
    {
        $settings = new Settings();
        $this->assertSame([], $settings->update(['slug' => 'anmelden'])['errors']);
        $this->assertSame('anmelden', (new Settings())->string('slug'));

        $this->assertNotSame([], $settings->update(['slug' => 'wp-admin'])['errors']);
        $this->assertSame('anmelden', (new Settings())->string('slug'), 'A rejected update must not change the stored value.');
    }

    public function test_every_error_code_has_a_message(): void
    {
        foreach ([['code' => 'slug_format'], ['code' => 'slug_reserved'], ['code' => 'out_of_range', 'min' => 1, 'max' => 2], ['code' => 'other']] as $error) {
            $this->assertNotSame('', Settings::errorMessage($error));
        }
    }
}
