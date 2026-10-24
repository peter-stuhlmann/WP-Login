<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use WP_UnitTestCase;

/**
 * Deleting the plugin removes all of its data – including personal data per user – and nothing else.
 *
 * @coversNothing
 */
final class UninstallTest extends WP_UnitTestCase
{
    public function test_uninstall_removes_settings_user_data_and_caches(): void
    {
        $user = self::factory()->user->create(['role' => 'editor', 'nickname' => 'Lena']);
        update_option('portier_settings', ['slug' => 'anmelden']);
        update_option('portier_css_revisions', [['time' => 1, 'author' => 'x', 'css' => 'a{}']]);
        update_user_meta($user, 'portier_2fa_secret', 'pc1:secret');
        update_user_meta($user, 'portier_2fa_recovery', ['hash']);
        update_user_meta($user, 'portier_sub_google', '1234');
        update_user_meta($user, 'portier_social_google', ['email' => 'lena@example.org']);
        update_user_meta($user, 'portier_magic', ['hash' => 'x']);
        update_user_meta($user, 'other_plugin_meta', 'keep');
        set_site_transient('portier_oidc_abc', ['issuer' => 'x'], HOUR_IN_SECONDS);
        set_transient('portier_2fa_codes_' . $user, ['A'], HOUR_IN_SECONDS);

        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'login/portier.php');
        }
        require dirname(__DIR__, 2) . '/uninstall.php';
        wp_cache_flush();

        $this->assertFalse(get_option('portier_settings'));
        $this->assertFalse(get_option('portier_css_revisions'));
        foreach (['portier_2fa_secret', 'portier_2fa_recovery', 'portier_sub_google', 'portier_social_google', 'portier_magic'] as $key) {
            $this->assertSame('', get_user_meta($user, $key, true), $key);
        }
        $this->assertSame('keep', get_user_meta($user, 'other_plugin_meta', true));
        $this->assertSame('Lena', get_user_meta($user, 'nickname', true));
        $this->assertFalse(get_site_transient('portier_oidc_abc'));
        $this->assertFalse(get_transient('portier_2fa_codes_' . $user));
        $this->assertInstanceOf(\WP_User::class, get_userdata($user), 'Accounts themselves stay.');
    }
}
