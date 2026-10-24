<?php

/**
 * Removes all Portier data when the plugin is deleted (not on deactivation).
 *
 * Core options such as users_can_register are left untouched: they belong to WordPress.
 *
 * @package Portier
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * Deletes the options of the current site.
 */
function portier_uninstall_site(): void
{
    delete_option('portier_settings');
    delete_option('portier_css_revisions');
    delete_option('portier_rewrite_signature');
    delete_option('rewrite_rules'); // Rebuilt without our route on the next request.

    // Short-lived per-site transients (e.g. recovery codes shown once after setup) and locks a
    // crashed request may have left behind.
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup, no caching needed.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_portier_') . '%',
            $wpdb->esc_like('_transient_timeout_portier_') . '%',
            $wpdb->esc_like('portier_lock_') . '%'
        )
    );
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $portier_site_id) {
        switch_to_blog((int) $portier_site_id);
        portier_uninstall_site();
        restore_current_blog();
    }
} else {
    portier_uninstall_site();
}

global $wpdb;

// Personal data stored per user: 2FA secrets and recovery codes, trusted devices, magic link
// tokens and social login connections (all user meta keys start with "portier_").
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup, no caching needed.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like('portier_') . '%'));
wp_cache_flush();

// Rate-limit counters, notice flags, OpenID discovery and key caches are (site) transients.
// Table and column as identifiers (%i) – core names, but never interpolated into the SQL.
$portier_table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
$portier_column = is_multisite() ? 'meta_key' : 'option_name';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup, no caching needed.
$wpdb->query(
    $wpdb->prepare(
        'DELETE FROM %i WHERE %i LIKE %s OR %i LIKE %s',
        $portier_table,
        $portier_column,
        $wpdb->esc_like('_site_transient_portier_') . '%',
        $portier_column,
        $wpdb->esc_like('_site_transient_timeout_portier_') . '%'
    )
);

// The audit log files and their protection files, wherever PORTIER_LOG_DIR puts them.
require_once __DIR__ . '/src/Audit/LogFile.php';
Portier\Audit\LogFile::fromConfig()->deleteAll();
