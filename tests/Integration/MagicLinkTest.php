<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Auth\PendingLogin;
use Portier\Frontend\MagicLinkScreens;
use Portier\Http\AfterResponse;
use Portier\Plugin;
use Portier\Settings\Settings;
use Portier\Tests\Support\ScreenRequests;
use Portier\TwoFactor\Totp;
use WP_UnitTestCase;
use WP_User;

/**
 * Magic link login: neutral request, e-mail, confirmation page, single use, expiry and the
 * hand-over to two-factor authentication.
 *
 * @covers \Portier\Auth\MagicLinkService
 * @covers \Portier\Frontend\MagicLinkScreens
 * @covers \Portier\Frontend\Device
 */
final class MagicLinkTest extends WP_UnitTestCase
{
    use ScreenRequests;

    private WP_User $user;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->jar = [];
        reset_phpmailer_instance();
        update_option(Settings::OPTION, ['magic_link' => true, 'magic_link_minutes' => 15]);
        $this->user = self::factory()->user->create_and_get(['user_login' => 'lena', 'user_email' => 'lena@example.org', 'role' => 'editor']);
    }

    /**
     * Requests a link and returns the token from the e-mail.
     */
    private function requestToken(string $login = 'lena@example.org'): string
    {
        $this->submit('magiclink', ['user_login' => $login]);
        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertNotFalse($mail, 'A mail was sent.');
        $this->assertSame(1, preg_match('#[?&]token=([a-f0-9]{18}\.[a-f0-9]{48})#', $mail->body, $match));
        return $match[1];
    }

    /**
     * Opens the link like a browser: the token moves into a cookie, then the confirm page shows.
     */
    private function openLink(string $token): \Portier\Http\Response
    {
        $moved = $this->send('GET', ['action' => 'magic', 'token' => $token]);
        $this->assertSame(302, $moved->status);
        $this->assertStringNotContainsString($token, (string) $moved->redirect, 'The token leaves the URL.');
        $this->assertSame($token, $this->jar[MagicLinkScreens::COOKIE] ?? null);
        return $this->send('GET', ['action' => 'magic']);
    }

    public function test_the_request_is_neutral_for_unknown_accounts(): void
    {
        $known = $this->submit('magiclink', ['user_login' => 'lena@example.org']);
        reset_phpmailer_instance();
        $unknown = $this->submit('magiclink', ['user_login' => 'nobody@example.org']);

        $this->assertSame($known->redirect, $unknown->redirect);
        $this->assertStringContainsString('checkemail=magic', (string) $unknown->redirect);
        $this->assertFalse(tests_retrieve_phpmailer_instance()->get_sent(), 'Unknown accounts get no mail.');
    }

    public function test_the_mail_goes_out_after_the_response(): void
    {
        // Its timing would otherwise tell whether the account exists.
        $this->assertTrue(Plugin::instance()->magicLinks('203.0.113.9')->request('lena', '')->success);
        $this->assertFalse(tests_retrieve_phpmailer_instance()->get_sent());
        AfterResponse::run();
        $this->assertNotFalse(tests_retrieve_phpmailer_instance()->get_sent());
    }

    public function test_switching_login_links_off_stops_links_already_sent(): void
    {
        $token = $this->requestToken();
        update_option(Settings::OPTION, ['magic_link' => false]);
        $this->assertSame(410, $this->openLink($token)->status);
    }

    public function test_accounts_that_may_not_log_in_get_no_link(): void
    {
        add_filter('portier_passwordless_login_allowed', '__return_false');
        $this->submit('magiclink', ['user_login' => 'lena@example.org']);
        $this->assertFalse(tests_retrieve_phpmailer_instance()->get_sent());
    }

    /**
     * @group ms-required
     */
    public function test_accounts_marked_as_spam_cannot_use_their_link(): void
    {
        global $wpdb;
        $token = $this->requestToken();
        $wpdb->update($wpdb->users, ['spam' => 1], ['ID' => $this->user->ID]);
        clean_user_cache($this->user->ID);
        $this->assertSame(410, $this->openLink($token)->status);
    }

    public function test_the_mail_contains_a_link_and_the_lifetime(): void
    {
        $this->requestToken();
        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertSame('lena@example.org', $mail->to[0][0]);
        $this->assertStringContainsString('15 minutes', $mail->body);
    }

    public function test_opening_the_link_does_not_log_in(): void
    {
        $page = $this->openLink($this->requestToken());
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Chrome', $page->body, 'The page names the requesting device.');
        $this->assertSame(0, get_current_user_id(), 'Mail scanners that open links cannot log in.');
    }

    public function test_confirming_logs_in_once(): void
    {
        $token = $this->requestToken();
        $this->openLink($token);

        $response = $this->submit('magic', []);
        $this->assertSame(admin_url(), $response->redirect);
        $this->assertSame($this->user->ID, get_current_user_id());
        $this->assertArrayNotHasKey(MagicLinkScreens::COOKIE, $this->jar);

        wp_set_current_user(0);
        $this->assertSame(410, $this->openLink($token)->status, 'A link works only once.');
    }

    public function test_confirming_needs_a_valid_form_token(): void
    {
        $this->openLink($this->requestToken());
        $response = $this->send('POST', ['action' => 'magic'], ['portier_token' => 'forged']);
        $this->assertSame(422, $response->status);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_only_the_newest_link_is_valid(): void
    {
        $first = $this->requestToken();
        reset_phpmailer_instance();
        $second = $this->requestToken('lena');

        $this->assertSame(410, $this->openLink($first)->status);
        $this->assertSame(200, $this->openLink($second)->status);
    }

    public function test_expired_links_are_rejected(): void
    {
        $token = $this->requestToken();
        $data = get_user_meta($this->user->ID, 'portier_magic', true);
        $data['expires'] = time() - 1;
        update_user_meta($this->user->ID, 'portier_magic', $data);

        $page = $this->openLink($token);
        $this->assertSame(410, $page->status);
        $this->assertArrayNotHasKey(MagicLinkScreens::COOKIE, $this->jar);
    }

    public function test_tampered_tokens_are_rejected(): void
    {
        $token = $this->requestToken();
        $tampered = substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a');
        $this->assertSame(410, $this->openLink($tampered)->status);
        $this->assertSame(410, $this->openLink('not-a-token')->status);
    }

    public function test_the_redirect_is_kept_with_the_link(): void
    {
        $this->submit('magiclink', ['user_login' => 'lena', 'redirect_to' => admin_url('edit.php')]);
        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        preg_match('#token=([a-f0-9.]+)#', $mail->body, $match);
        $this->openLink($match[1]);
        $this->assertSame(admin_url('edit.php'), $this->submit('magic', [])->redirect);
    }

    public function test_two_factor_still_applies(): void
    {
        update_option(Settings::OPTION, ['magic_link' => true, 'two_factor_mode' => 'optional']);
        $store = Plugin::instance()->twoFactor()->store();
        $store->enable($this->user->ID, $store->pendingSecret($this->user->ID), Totp::step(time()) - 5);

        $this->openLink($this->requestToken());
        $response = $this->submit('magic', []);
        $this->assertStringContainsString('action=2fa', (string) $response->redirect);
        $this->assertArrayHasKey(PendingLogin::COOKIE, $this->jar);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_disabled_magic_links_redirect_to_the_login(): void
    {
        update_option(Settings::OPTION, ['magic_link' => false]);
        $response = $this->send('GET', ['action' => 'magiclink']);
        $this->assertSame(home_url('/login/'), $response->redirect);
    }
}
