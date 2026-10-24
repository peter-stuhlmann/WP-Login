<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Frontend\Controller;
use Portier\Settings\Settings;
use Portier\Tests\Support\ScreenRequests;
use WP_UnitTestCase;

/**
 * Login in two steps (e-mail, then password) and the switch back to one screen.
 *
 * @covers \Portier\Frontend\Controller
 * @covers \Portier\Frontend\Responder
 */
final class TwoStepLoginTest extends WP_UnitTestCase
{
    use ScreenRequests;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->jar = [];
        update_option(Settings::OPTION, ['login_steps' => 'two']);
        self::factory()->user->create(['user_login' => 'lena', 'user_email' => 'lena@example.org', 'user_pass' => 'correct-horse-battery', 'role' => 'editor']);
    }

    private function identify(string $login): \Portier\Http\Response
    {
        return $this->submit('login', ['portier_step' => 'identify', 'log' => $login]);
    }

    public function test_step_one_asks_only_for_the_login(): void
    {
        $page = $this->send('GET')->body;
        $this->assertStringContainsString('name="portier_step" value="identify"', $page);
        $this->assertStringContainsString('autocomplete="username"', $page);
        $this->assertStringNotContainsString('type="password"', $page);
    }

    public function test_the_login_moves_to_step_two_without_appearing_in_the_url(): void
    {
        $response = $this->identify('lena@example.org');
        $this->assertSame(303, $response->status);
        $this->assertStringContainsString('step=password', (string) $response->redirect);
        $this->assertStringNotContainsString('lena', (string) $response->redirect, 'No e-mail address in URLs or logs.');
        $this->assertSame('lena@example.org', $this->jar[Controller::LOGIN_COOKIE]);

        $page = $this->send('GET', ['step' => 'password']);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('lena@example.org', $page->body);
        $this->assertStringContainsString('autocomplete="current-password"', $page->body);
        $this->assertMatchesRegularExpression('/<input type="text" name="log" value="lena@example.org" autocomplete="username" hidden>/', $page->body, 'Password managers need the username.');
        $this->assertStringContainsString('autofocus', $page->body);
    }

    public function test_unknown_accounts_look_exactly_the_same(): void
    {
        $known = $this->identify('lena@example.org');
        $this->jar = [];
        $unknown = $this->identify('nobody@example.org');
        $this->assertSame($known->status, $unknown->status);
        $this->assertSame($known->redirect, $unknown->redirect);
    }

    public function test_an_empty_login_is_explained(): void
    {
        $response = $this->identify('   ');
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('aria-invalid="true"', $response->body);
        $this->assertArrayNotHasKey(Controller::LOGIN_COOKIE, $this->jar);
    }

    public function test_step_two_logs_in_and_forgets_the_login(): void
    {
        $this->identify('lena@example.org');
        $response = $this->submit('login', ['portier_step' => 'password', 'log' => 'lena@example.org', 'pwd' => 'correct-horse-battery']);
        $this->assertSame(admin_url(), $response->redirect);
        $this->assertGreaterThan(0, get_current_user_id());
        $this->assertArrayNotHasKey(Controller::LOGIN_COOKIE, $this->jar);
    }

    public function test_a_wrong_password_stays_on_step_two(): void
    {
        $this->identify('lena@example.org');
        $response = $this->submit('login', ['portier_step' => 'password', 'log' => 'lena@example.org', 'pwd' => 'wrong']);
        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('name="portier_step" value="password"', $response->body);
        $this->assertStringContainsString('portier-errors', $response->body);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_step_two_without_step_one_starts_over(): void
    {
        $response = $this->send('GET', ['step' => 'password', 'redirect_to' => admin_url('edit.php')]);
        $this->assertStringNotContainsString('step=password', (string) $response->redirect);
        $this->assertStringContainsString('redirect_to=', (string) $response->redirect, 'The target is kept.');
    }

    public function test_change_goes_back_with_the_login_prefilled(): void
    {
        $this->identify('lena@example.org');
        $page = $this->send('GET')->body;
        $this->assertMatchesRegularExpression('/name="log"[^>]*value="lena@example.org"/s', $page);
    }

    public function test_the_target_survives_both_steps(): void
    {
        $response = $this->submit('login', ['portier_step' => 'identify', 'log' => 'lena', 'redirect_to' => admin_url('edit.php')]);
        $this->assertStringContainsString(rawurlencode(admin_url('edit.php')), (string) $response->redirect);
        $step2 = $this->send('GET', ['step' => 'password', 'redirect_to' => admin_url('edit.php')]);
        $this->assertStringContainsString('name="redirect_to" value="' . admin_url('edit.php') . '"', $step2->body);
    }

    public function test_fragment_requests_focus_the_password(): void
    {
        $this->identify('lena@example.org');
        $json = json_decode($this->send('GET', ['step' => 'password'], [], [], true)->body, true);
        $this->assertSame('portier-pwd', $json['focus']);
    }

    public function test_step_two_shows_back_instead_of_the_logo(): void
    {
        $this->identify('lena@example.org');
        $page = $this->send('GET', ['step' => 'password'])->body;
        $this->assertMatchesRegularExpression('/<a class="portier-back" href="[^"]*\/login\/" data-portier-nav data-portier-back>/', $page);
        $this->assertMatchesRegularExpression('/data-portier-brand hidden>/', $page);

        $json = json_decode($this->send('GET', ['step' => 'password'], [], [], true)->body, true);
        $this->assertStringEndsWith('/login/', $json['back']);
        $this->assertSame('', json_decode($this->send('GET', [], [], [], true)->body, true)['back'], 'Step 1 shows the logo.');
    }

    public function test_keep_me_logged_in_survives_a_wrong_password(): void
    {
        $this->identify('lena@example.org');
        $error = $this->submit('login', ['portier_step' => 'password', 'log' => 'lena@example.org', 'pwd' => 'wrong', 'rememberme' => '1']);
        $this->assertMatchesRegularExpression('/id="portier-rememberme"[^>]*checked/s', $error->body);
    }

    public function test_after_an_error_the_heading_is_log_in(): void
    {
        $this->identify('lena@example.org');
        $error = $this->submit('login', ['portier_step' => 'password', 'log' => 'lena@example.org', 'pwd' => 'wrong']);
        $this->assertMatchesRegularExpression('#<h1 class="portier-title"[^>]*>Log in</h1>#', $error->body);
        $this->assertMatchesRegularExpression('#<h1 class="portier-title"[^>]*>Enter your password</h1>#', $this->send('GET', ['step' => 'password'])->body);
    }

    public function test_forms_posting_both_fields_keep_working(): void
    {
        $response = $this->submit('login', ['log' => 'lena', 'pwd' => 'correct-horse-battery']);
        $this->assertSame(admin_url(), $response->redirect);
    }

    public function test_one_step_mode_shows_both_fields(): void
    {
        update_option(Settings::OPTION, ['login_steps' => 'one']);
        $page = $this->send('GET')->body;
        $this->assertStringContainsString('type="password"', $page);
        $this->assertStringNotContainsString('portier_step', $page);
        // A stray "identify" post in one-step mode is a normal (incomplete) login attempt.
        $this->assertSame(422, $this->identify('lena')->status);
    }

    public function test_the_setting_is_validated(): void
    {
        $this->assertSame('one', Settings::defaults()['login_steps']);
        $this->assertSame('one', (new Settings())->sanitize(['login_steps' => 'three'])['values']['login_steps']);
        $this->assertSame('two', (new Settings())->sanitize(['login_steps' => 'two'])['values']['login_steps']);
    }
}
