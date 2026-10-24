<?php

/**
 * Plugin constants defined at runtime that PHPStan cannot see.
 * (WordPress' own constants come from the phpstan-wordpress stubs.)
 *
 * @package Portier
 */

define('PORTIER_VERSION', '0.0.0');
define('PORTIER_FILE', __DIR__ . '/../portier.php');
define('PORTIER_DIR', dirname(__DIR__));

// Defined by wp_cookie_constants() at runtime; not part of the stubs.
define('COOKIEHASH', 'hash');
define('COOKIEPATH', '/');
