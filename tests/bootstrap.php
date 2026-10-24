<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library (wp-phpunit) and Portier as a mu-plugin.
 *
 * @package Portier
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$portier_config = dirname(__DIR__) . '/wp-tests-config.php';
if (! is_readable($portier_config)) {
    $portier_config = dirname(__DIR__) . '/wp-tests-config-sample.php';
}
putenv('WP_PHPUNIT__TESTS_CONFIG=' . $portier_config);

$portier_tests_dir = getenv('WP_PHPUNIT__DIR') ?: dirname(__DIR__) . '/vendor/wp-phpunit/wp-phpunit';
require_once $portier_tests_dir . '/includes/functions.php';

tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        require dirname(__DIR__) . '/portier.php';
    }
);

// The audit log of the test runs goes to the temp directory, never into a WordPress install.
define('PORTIER_LOG_DIR', sys_get_temp_dir() . '/portier-phpunit-logs');
// PHPUnit runs on the command line, but the tests simulate web requests (IP, browser, source).
define('PORTIER_AUDIT_TESTING', true);

// Tests run on the CLI: never try to send real auth cookies.
tests_add_filter('send_auth_cookies', '__return_false');

require $portier_tests_dir . '/includes/bootstrap.php';
