<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Routing\LoginRoute;
use Portier\Routing\UrlRewriter;
use WP_UnitTestCase;

/**
 * The plugin is booted with default settings (slug "login") by the test bootstrap, so the
 * UrlRewriter filters are active in these tests.
 *
 * @covers \Portier\Routing\LoginRoute
 * @covers \Portier\Routing\UrlRewriter
 */
final class RoutingTest extends WP_UnitTestCase
{
    public function test_pretty_permalink_url(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $route = new LoginRoute('login', true);
        $this->assertSame(home_url('/login/'), $route->url());
        $this->assertSame(home_url('/login/?action=lostpassword'), $route->url('lostpassword'));
    }

    public function test_plain_permalink_url(): void
    {
        $this->set_permalink_structure('');
        $route = new LoginRoute('login', true);
        $this->assertSame(home_url('/?portier=1'), $route->url());
        $this->assertStringContainsString('action=register', $route->url('register'));
    }

    public function test_query_arguments_are_encoded(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $url = (new LoginRoute('login', true))->url('login', ['redirect_to' => 'https://example.org/a?b=c&d=e']);
        $this->assertStringContainsString('redirect_to=https%3A%2F%2Fexample.org%2Fa%3Fb%3Dc%26d%3De', $url);
    }

    public function test_owns_recognises_its_own_urls(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $route = new LoginRoute('login', true);
        $this->assertTrue($route->owns($route->url('checkemail', ['checkemail' => 'confirm'])));
        $this->assertFalse($route->owns(admin_url()));
        $this->assertFalse($route->owns('https://evil.example/login/'));
    }

    public function test_rewrite_rule_is_registered_and_flushed_once(): void
    {
        $this->set_permalink_structure('/%postname%/');
        delete_option(LoginRoute::SIGNATURE_OPTION);
        $route = new LoginRoute('anmelden', true);
        $route->addRewriteRule();
        $route->maybeFlush();

        $rules = get_option('rewrite_rules');
        $this->assertContains('index.php?portier=1', $rules);
        $this->assertNotEmpty(get_option(LoginRoute::SIGNATURE_OPTION));
    }

    public function test_core_login_links_point_to_portier(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $this->assertStringStartsWith(home_url('/login/'), wp_login_url());
        $this->assertStringContainsString('redirect_to=', wp_login_url(admin_url()));
        $this->assertStringContainsString('/login/?action=lostpassword', wp_lostpassword_url());
        $this->assertStringContainsString('/login/?action=register', wp_registration_url());
    }

    public function test_pass_through_actions_keep_using_core(): void
    {
        $this->assertStringContainsString('wp-login.php?action=logout', wp_logout_url());
        $this->assertStringContainsString('wp-login.php?action=postpass', site_url('wp-login.php?action=postpass', 'login_post'));
    }

    public function test_login_widgets_still_post_to_core(): void
    {
        // wp_login_form() uses the login_post scheme: those forms keep working against core.
        $this->assertStringContainsString('wp-login.php', site_url('wp-login.php', 'login_post'));
    }

    public function test_reset_links_in_emails_are_rewritten(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $url = network_site_url('wp-login.php?action=rp&key=abc123&login=lena', 'login');
        $this->assertStringContainsString('/login/?action=rp', $url);
        $this->assertStringContainsString('key=abc123', $url);
        $this->assertStringContainsString('login=lena', $url);
    }

    public function test_relative_core_redirects_are_rewritten(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $rewriter = new UrlRewriter(new LoginRoute('login', true));
        $this->assertStringContainsString('/login/?loggedout=true', $rewriter->filterRedirect('wp-login.php?loggedout=true'));
        $this->assertSame('https://other.example/wp-login.php', $rewriter->filterRedirect('https://other.example/wp-login.php'), 'Foreign hosts stay untouched.');
        $this->assertSame(home_url('/shop/'), $rewriter->filterRedirect(home_url('/shop/')));
    }
}
