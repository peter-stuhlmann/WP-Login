<?php

/**
 * Tiny CLI helper for the end-to-end tests: prepares the WordPress site under test.
 *
 * Usage: php wp-cli.php <path-to-wp-load.php> <command> [user]
 *
 * setup:       creates the test users, turns on magic links and required 2FA for contributors
 *              (the previous settings are backed up) and clears Portier rate-limit counters.
 * teardown:    deletes the users and restores the settings.
 * 2fa-on:      turns 2FA on for "portier-e2e-2fa" with a fixed secret; prints the recovery codes
 *              as JSON. Also resets replay protection, so every test can use the current code.
 * 2fa-off:     turns 2FA off for the given user (default "portier-e2e-setup").
 * magic-link:  prints a fresh magic login link for the given user (default "portier-e2e").
 * steps:       login screen in one or two steps (argument "one" or "two").
 * registration: opens or closes the registration ("on" | "off"); restored on teardown.
 *
 * @package Portier
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

[, $portier_wp_load, $portier_command, $portier_arg] = $argv + [null, null, null, null];
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require $portier_wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';

const PORTIER_E2E_USER = 'portier-e2e';
const PORTIER_E2E_PASS = 'e2e-Correct-Horse-42';
// Base32 test secret; tests/e2e/support/totp.mjs computes the matching codes.
const PORTIER_E2E_SECRET = 'PORTIERE2ESECRETKEYFORTESTSAAAAA';
const PORTIER_E2E_BACKUP = 'portier_e2e_settings_backup';
// Must-use plugin that registers the local test identity provider.
const PORTIER_E2E_MU_PLUGIN = 'portier-e2e-idp.php';

// Clear rate-limit counters, so repeated runs start fresh.
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_portier\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_portier\\_%'");
if (is_multisite()) {
    $wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '\\_site\\_transient\\_portier\\_%' OR meta_key LIKE '\\_site\\_transient\\_timeout\\_portier\\_%'");
}
wp_cache_flush();

// An editor for the login journeys and an administrator for the settings screen.
$portier_users = [
    PORTIER_E2E_USER => 'editor',
    PORTIER_E2E_USER . '-admin' => 'administrator',
    PORTIER_E2E_USER . '-2fa' => 'author',
    PORTIER_E2E_USER . '-setup' => 'contributor',
];

$portier = \Portier\Plugin::instance();
$portier_user = static function (string $login): WP_User {
    $user = get_user_by('login', $login);
    if (! $user instanceof WP_User) {
        fwrite(STDERR, "Unknown user {$login}. Run setup first.\n");
        exit(1);
    }
    return $user;
};

foreach ($portier_users as $portier_login => $portier_role) {
    $portier_existing = get_user_by('login', $portier_login);
    if ($portier_command === 'setup') {
        if (! $portier_existing) {
            wp_insert_user([
                'user_login' => $portier_login,
                'user_email' => $portier_login . '@example.org',
                'user_pass' => PORTIER_E2E_PASS,
                'role' => $portier_role,
                'display_name' => 'E2E ' . $portier_role,
            ]);
        } else {
            wp_set_password(PORTIER_E2E_PASS, $portier_existing->ID);
        }
    } elseif ($portier_command === 'teardown' && $portier_existing) {
        wp_delete_user($portier_existing->ID);
    }
}

switch ($portier_command) {
    case 'setup':
        if (get_option(PORTIER_E2E_BACKUP) === false) {
            update_option(PORTIER_E2E_BACKUP, (array) get_option('portier_settings', []), false);
        }
        // Local test identity provider (fake-idp.php), registered through a must-use plugin.
        wp_mkdir_p(WPMU_PLUGIN_DIR);
        copy(__DIR__ . '/mu-plugin-idp.php', WPMU_PLUGIN_DIR . '/' . PORTIER_E2E_MU_PLUGIN);
        // Cached discovery documents and keys from older runs must not be reused.
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_%portier\\_oidc\\_%' OR option_name LIKE '\\_site\\_transient\\_%portier\\_jwks\\_%'");
        // The tests expect the default design, whatever the site uses (restored on teardown).
        $portier_design = array_intersect_key(\Portier\Settings\Settings::defaults(), array_flip([
            'template', 'color_scheme', 'design_headline', 'design_text', 'custom_primary', 'custom_accent',
            'custom_background', 'custom_radius', 'custom_font', 'custom_css', 'bg_image', 'bg_image_dark',
            'bg_dark', 'bg_focus_x', 'bg_focus_y', 'bg_overlay', 'bg_mobile',
        ]));
        $portier->settings()->update($portier_design + [
            // PORTIER_E2E_STEPS=two runs the whole suite with the two-step login.
            'login_steps' => getenv('PORTIER_E2E_STEPS') === 'two' ? 'two' : 'one',
            'magic_link' => true,
            'two_factor_mode' => 'required',
            'two_factor_roles' => ['contributor'],
            'providers' => [
                'e2eidp' => [
                    'enabled' => true,
                    'client_id' => 'e2e-client',
                    'client_secret' => \Portier\Security\Crypto::fromWordPress()->encrypt('e2e-secret'),
                    'link' => 'verified',
                    'signup' => false,
                ],
            ],
        ]);
        echo "ready\n";
        break;

    case 'teardown':
        // Restore the previous settings (and the default address, in case an admin test was interrupted).
        $portier_settings = (array) get_option(PORTIER_E2E_BACKUP, get_option('portier_settings', []));
        $portier_settings['slug'] = 'login';
        update_option('portier_settings', $portier_settings);
        delete_option(PORTIER_E2E_BACKUP);
        $portier_registration = get_option('portier_e2e_registration_backup');
        if ($portier_registration !== false) {
            update_option('users_can_register', (int) $portier_registration);
            delete_option('portier_e2e_registration_backup');
        }
        foreach (get_posts(['post_type' => 'attachment', 'meta_key' => '_portier_e2e', 'fields' => 'ids', 'numberposts' => -1]) as $portier_attachment) {
            wp_delete_attachment((int) $portier_attachment, true);
        }
        wp_delete_file(WPMU_PLUGIN_DIR . '/' . PORTIER_E2E_MU_PLUGIN);
        echo "removed\n";
        break;

    case 'photo':
        // Background image from tests/e2e/fixtures (imported once), optional overlay transparency.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $portier_ids = [];
        foreach (['background.jpg', 'background-dark.jpg'] as $portier_file) {
            $portier_found = get_posts(['post_type' => 'attachment', 'meta_key' => '_portier_e2e', 'meta_value' => $portier_file, 'fields' => 'ids', 'numberposts' => 1]);
            if ($portier_found === []) {
                $portier_tmp = wp_tempnam($portier_file);
                copy(dirname(__DIR__) . '/fixtures/' . $portier_file, $portier_tmp);
                $portier_id = media_handle_sideload(['name' => 'portier-e2e-' . $portier_file, 'tmp_name' => $portier_tmp], 0);
                if (is_wp_error($portier_id)) {
                    fwrite(STDERR, $portier_id->get_error_message() . "\n");
                    exit(1);
                }
                update_post_meta($portier_id, '_portier_e2e', $portier_file);
                $portier_found = [$portier_id];
            }
            $portier_ids[] = (int) $portier_found[0];
        }
        $portier->settings()->update([
            'bg_image' => $portier_ids[0],
            'bg_image_dark' => $portier_ids[1],
            'bg_overlay' => $portier_arg !== null && $portier_arg !== '' ? (int) $portier_arg : 50,
        ]);
        echo "photo {$portier_ids[0]}\n";
        break;

    case 'photo-off':
        $portier->settings()->update(['bg_image' => 0, 'bg_image_dark' => 0, 'bg_dark' => false]);
        echo "photo off\n";
        break;

    case 'template':
        // Design template of the login page (one of Portier\Design\Templates::ids()).
        $portier->settings()->update(['template' => (string) $portier_arg]);
        echo "template {$portier->settings()->string('template')}\n";
        break;

    case 'registration':
        // Open or close the registration; the site's own setting comes back on teardown.
        if (get_option('portier_e2e_registration_backup') === false) {
            update_option('portier_e2e_registration_backup', (string) (int) get_option('users_can_register'), false);
        }
        update_option('users_can_register', $portier_arg === 'off' ? 0 : 1);
        echo 'registration ' . (get_option('users_can_register') ? 'on' : 'off') . "
";
        break;

    case 'steps':
        // Login screen in one or two steps ("one" | "two").
        $portier->settings()->update(['login_steps' => $portier_arg === 'one' ? 'one' : 'two']);
        echo "steps {$portier->settings()->string('login_steps')}\n";
        break;

    case '2fa-on':
        $portier_id = $portier_user(PORTIER_E2E_USER . '-2fa')->ID;
        $portier->twoFactor()->store()->forgetDevices($portier_id);
        echo wp_json_encode(['codes' => $portier->twoFactor()->store()->enable($portier_id, PORTIER_E2E_SECRET, 0)]), "\n";
        break;

    case '2fa-off':
        $portier->twoFactor()->store()->disable($portier_user($portier_arg ?: PORTIER_E2E_USER . '-setup')->ID);
        echo "off\n";
        break;

    case 'magic-link':
        $portier_token = $portier->magicLinks('127.0.0.1')->issue($portier_user($portier_arg ?: PORTIER_E2E_USER));
        $portier_route = new \Portier\Routing\LoginRoute($portier->settings()->string('slug'), true);
        echo $portier_route->url('magic', ['token' => $portier_token]), "\n";
        break;

    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(1);
}
