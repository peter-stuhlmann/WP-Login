<?php

/**
 * Plugin Name:       Portier
 * Plugin URI:        https://github.com/peter-stuhlmann/WP-Login
 * Description:       A modern, accessible custom login for WordPress: own login URL, beautiful templates, secure by default.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Peter R. Stuhlmann
 * Author URI:        https://peter-stuhlmann-webentwicklung.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       portier
 * Domain Path:       /languages
 *
 * @package Portier
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('PORTIER_VERSION', '1.0.0');
define('PORTIER_FILE', __FILE__);
define('PORTIER_DIR', __DIR__);

/*
 * Runtime autoloader for the Portier\ namespace (PSR-4, src/).
 * The plugin has no runtime Composer dependencies, so it ships without vendor/.
 */
spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Portier\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) {
            require $file;
        }
    }
);

register_activation_hook(__FILE__, [Portier\Lifecycle::class, 'activate']);
register_deactivation_hook(__FILE__, [Portier\Lifecycle::class, 'deactivate']);

add_action(
    'plugins_loaded',
    static function (): void {
        $requirements = new Portier\Requirements();
        if (! $requirements->met()) {
            $requirements->showAdminNotice();
            return;
        }
        Portier\Plugin::instance()->boot();
    }
);
