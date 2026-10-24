<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Frontend\Controller;
use Portier\Http\Request;
use Portier\Http\Response;
use Portier\Plugin;
use Portier\Security\FormToken;
use WP_UnitTestCase;

/**
 * End-to-end behaviour of the screens, without a browser: requests in, responses out.
 *
 * @covers \Portier\Frontend\Controller
 * @covers \Portier\Frontend\View
 * @covers \Portier\Http\Response
 */
final class ControllerTest extends WP_UnitTestCase
{
    private const COOKIE = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        reset_phpmailer_instance();
        self::factory()->user->create(['user_login' => 'lena', 'user_email' => 'lena@example.org', 'user_pass' => 'correct-horse-battery', 'role' => 'editor']);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, string> $cookies
     */
    private function send(string $method, array $query = [], array $post = [], array $cookies = [], bool $fragment = false): Response
    {
        $request = new Request(
            $method,
            $query,
            $post,
            ['REMOTE_ADDR' => '203.0.113.9'] + ($fragment ? ['HTTP_X_PORTIER_REQUEST' => 'fragment'] : []),
            $cookies + [FormToken::COOKIE => self::COOKIE]
        );
        return Plugin::instance()->controller($request)->handle($request);
    }

    private function token(string $action): string
    {
        return (new FormToken(wp_salt('nonce'), self::COOKIE))->create($action);
    }

    public function test_login_page_is_accessible_markup(): void
    {
        update_option(\Portier\Settings\Settings::OPTION, ['login_steps' => 'one']);
        $response = $this->send('GET');
        $this->assertSame(200, $response->status);
        $html = $response->body;
        $this->assertStringContainsString('<html', $html);
        $this->assertMatchesRegularExpression('/<label class="portier-label" for="portier-log">/', $html);
        $this->assertStringContainsString('autocomplete="username"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('id="portier-title"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('name="portier_token"', $html);
        $this->assertStringContainsString('class="portier-skip-link"', $html);
    }

    public function test_security_headers_are_sent(): void
    {
        $headers = $this->send('GET')->headers();
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
    }

    public function test_a_csrf_cookie_is_issued_to_new_visitors(): void
    {
        $request = new Request('GET', [], [], ['REMOTE_ADDR' => '203.0.113.9'], []);
        $response = Plugin::instance()->controller($request)->handle($request);
        $this->assertSame(FormToken::COOKIE, $response->cookies()[0]['name']);
    }

    public function test_posts_without_cookie_or_with_a_forged_token_are_rejected(): void
    {
        $request = new Request('POST', [], ['log' => 'lena', 'pwd' => 'correct-horse-battery', 'portier_token' => 'x'], ['REMOTE_ADDR' => '203.0.113.9'], []);
        $noCookie = Plugin::instance()->controller($request)->handle($request);
        $this->assertStringContainsString('cookies', $noCookie->body);

        $forged = $this->send('POST', [], ['log' => 'lena', 'pwd' => 'correct-horse-battery', 'portier_token' => 'forged']);
        $this->assertSame(422, $forged->status);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_successful_login_redirects(): void
    {
        $response = $this->send('POST', [], ['log' => 'lena', 'pwd' => 'correct-horse-battery', 'portier_token' => $this->token('login')]);
        $this->assertSame(303, $response->status);
        $this->assertSame(admin_url(), $response->redirect);
    }

    public function test_failed_login_rerenders_with_the_neutral_error(): void
    {
        $response = $this->send('POST', [], ['log' => 'lena', 'pwd' => 'wrong', 'portier_token' => $this->token('login')]);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('id="portier-errors"', $response->body);
        $this->assertStringContainsString('value="lena"', $response->body, 'The login is kept.');
        $this->assertStringContainsString('aria-invalid="true"', $response->body);
    }

    public function test_fragment_requests_get_json(): void
    {
        $response = $this->send('GET', ['action' => 'lostpassword'], [], [], true);
        $data = json_decode($response->body, true);
        $this->assertStringContainsString('name="user_login"', $data['html']);
        $this->assertSame('heading', $data['focus']);
        $this->assertStringNotContainsString('<html', $data['html']);

        $login = $this->send('POST', [], ['log' => 'lena', 'pwd' => 'correct-horse-battery', 'portier_token' => $this->token('login')], [], true);
        $this->assertSame(['redirect' => admin_url(), 'internal' => false], json_decode($login->body, true));
    }

    public function test_logged_in_users_see_a_continue_screen(): void
    {
        wp_set_current_user((int) get_user_by('login', 'lena')->ID);
        $response = $this->send('GET');
        $this->assertStringContainsString(admin_url(), $response->body);
        $this->assertStringContainsString('action=logout', $response->body);
    }

    public function test_lost_password_redirects_to_the_neutral_confirmation(): void
    {
        $response = $this->send('POST', ['action' => 'lostpassword'], ['user_login' => 'ghost@example.org', 'portier_token' => $this->token('lostpassword')]);
        $this->assertSame(303, $response->status);
        $this->assertStringContainsString('checkemail=confirm', $response->redirect);
    }

    public function test_reset_link_moves_the_key_into_a_cookie(): void
    {
        $user = get_user_by('login', 'lena');
        $key = get_password_reset_key($user);

        $response = $this->send('GET', ['action' => 'rp', 'key' => $key, 'login' => 'lena']);
        $this->assertSame(302, $response->status);
        $this->assertStringNotContainsString($key, (string) $response->redirect, 'The key must leave the URL.');
        $cookie = $response->cookies()[0];
        $this->assertSame('lena:' . $key, $cookie['value']);

        $form = $this->send('GET', ['action' => 'rp'], [], [$cookie['name'] => $cookie['value']]);
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('autocomplete="new-password"', $form->body);

        $done = $this->send('POST', ['action' => 'rp'], ['pass1' => 'a-brand-new-passphrase', 'pass2' => 'a-brand-new-passphrase', 'portier_token' => $this->token('rp')], [$cookie['name'] => $cookie['value']]);
        $this->assertSame(303, $done->status);
        $this->assertStringContainsString('notice=password-changed', $done->redirect);
    }

    public function test_invalid_reset_cookie_shows_the_expired_screen(): void
    {
        $response = $this->send('GET', ['action' => 'rp'], [], ['wp-resetpass-' . COOKIEHASH => 'lena:forged']);
        $this->assertSame(410, $response->status);
    }

    public function test_registration_closed(): void
    {
        $this->setRegistration(false);
        $this->assertSame(403, $this->send('GET', ['action' => 'register'])->status);
    }

    public function test_registration_bot_traps_answer_like_success_but_create_nothing(): void
    {
        $this->setRegistration(true);
        $users = count_users()['total_users'];
        $token = new FormToken(wp_salt('nonce'), self::COOKIE);

        $honeypot = $this->send('POST', ['action' => 'register'], [
            'user_email' => 'bot@example.org', Controller::HONEYPOT_FIELD => 'http://spam', 'portier_ts' => $token->timestamp('register', time() - 30),
            'portier_token' => $this->token('register'), 'privacy_consent' => '1',
        ]);
        $tooFast = $this->send('POST', ['action' => 'register'], [
            'user_email' => 'fast@example.org', 'portier_ts' => $token->timestamp('register'), 'portier_token' => $this->token('register'), 'privacy_consent' => '1',
        ]);

        foreach ([$honeypot, $tooFast] as $response) {
            $this->assertSame(303, $response->status);
            $this->assertStringContainsString('checkemail=registered', $response->redirect);
        }
        $this->assertSame($users, count_users()['total_users']);
    }

    /**
     * @group ms-excluded
     */
    public function test_human_registration_creates_the_account(): void
    {
        $this->setRegistration(true);
        $token = new FormToken(wp_salt('nonce'), self::COOKIE);
        $response = $this->send('POST', ['action' => 'register'], [
            'user_email' => 'human@example.org', 'display_name' => 'Hu Man', 'portier_ts' => $token->timestamp('register', time() - 30),
            'portier_token' => $this->token('register'), 'privacy_consent' => '1',
        ]);
        $this->assertSame(303, $response->status);
        $this->assertNotFalse(get_user_by('email', 'human@example.org'));
    }

    public function test_interim_login_is_handed_to_core(): void
    {
        $response = $this->send('GET', ['interim-login' => '1']);
        $this->assertStringContainsString('wp-login.php?interim-login=1', (string) $response->redirect);
    }

    public function test_notices_after_logout_and_password_change(): void
    {
        $this->assertStringContainsString('role="status"', $this->send('GET', ['loggedout' => 'true'])->body);
        $this->assertStringContainsString('role="status"', $this->send('GET', ['password' => 'changed'])->body);
    }

    private function setRegistration(bool $open): void
    {
        if (is_multisite()) {
            update_site_option('registration', $open ? 'user' : 'none');
        } else {
            update_option('users_can_register', $open ? 1 : 0);
        }
    }
}
