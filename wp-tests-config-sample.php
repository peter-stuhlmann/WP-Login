<?php
/**
 * Configuration for the WordPress integration tests.
 *
 * Copy to wp-tests-config.php (git-ignored) and adjust. The test database is wiped on every
 * run – NEVER point it at a database with real data.
 *
 * @package Portier
 */

// Path to a WordPress installation (core files only are used, not its database).
define('ABSPATH', getenv('WP_CORE_DIR') ?: dirname(__DIR__, 3) . '/');

define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'portier_tests');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Portier Tests');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');
define('WP_DEBUG', true);

// Unique keys are not needed for tests.
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $portier_key) {
    define($portier_key, 'portier-tests-' . $portier_key);
}
