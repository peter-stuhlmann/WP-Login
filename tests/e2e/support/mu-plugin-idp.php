<?php

/**
 * Plugin Name: Portier E2E test identity provider
 * Description: Registers the local test provider (fake-idp.php) for Portier's end-to-end tests.
 *              Copied to wp-content/mu-plugins by the test setup and removed by the teardown.
 *
 * @package Portier
 */

declare(strict_types=1);

add_filter('portier_oauth_providers', static function (array $providers): array {
    if (defined('PORTIER_FILE') && class_exists(\Portier\OAuth\Provider::class)) {
        $providers[] = new \Portier\OAuth\Provider(
            id: 'e2eidp',
            label: 'Test IdP',
            scopes: ['openid', 'email', 'profile'],
            discovery: plugins_url('tests/e2e/support/fake-idp.php/.well-known/openid-configuration', PORTIER_FILE),
        );
    }
    return $providers;
});
