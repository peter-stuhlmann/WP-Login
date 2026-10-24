<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Auth\AuthResult;
use Portier\Auth\LoginFlow;
use Portier\Auth\LoginService;
use Portier\Auth\PasswordResetService;
use Portier\Auth\PendingLogin;
use Portier\Auth\RedirectResolver;
use Portier\Http\AfterResponse;
use Portier\Plugin;
use Portier\Security\RateLimiter;
use Portier\Tests\Support\InMemoryAttemptStore;
use WP_Error;
use WP_Session_Tokens;
use WP_UnitTestCase;
use WP_User;

/**
 * @covers \Portier\Auth\LoginService
 * @covers \Portier\Auth\RedirectResolver
 * @covers \Portier\Auth\PasswordResetService
 * @covers \Portier\Auth\AuthResult
 */
final class AuthServicesTest extends WP_UnitTestCase
{
    /**
     * Login flow with the plugin's real two-factor service (inactive by default).
     */
    private static function flow(): LoginFlow
    {
        return new LoginFlow(Plugin::instance()->twoFactor(), new PendingLogin());
    }

    private WP_User $user;

    public function set_up(): void
    {
        parent::set_up();
        reset_phpmailer_instance();
        $this->user = self::factory()->user->create_and_get([
            'user_login' => 'lena',
            'user_email' => 'lena@example.org',
            'user_pass' => 'correct-horse-battery',
            'role' => 'administrator',
        ]);
    }

    // ------------------------------------------------------------- Login

    public function test_login_with_username_or_email(): void
    {
        $service = new LoginService(self::flow());
        foreach (['lena', 'lena@example.org'] as $login) {
            $result = $service->attempt($login, 'correct-horse-battery', false, '');
            $this->assertTrue($result->success, $login);
            $this->assertSame(admin_url(), $result->redirect);
            $this->assertSame($this->user->ID, get_current_user_id());
        }
    }

    public function test_wrong_password_and_unknown_user_get_the_same_neutral_message(): void
    {
        $service = new LoginService(self::flow());
        $wrongPassword = $service->attempt('lena', 'nope', false, '');
        $unknownUser = $service->attempt('nobody@example.org', 'nope', false, '');

        $this->assertFalse($wrongPassword->success);
        $this->assertSame($wrongPassword->errors, $unknownUser->errors, 'Both cases must be indistinguishable.');
        $this->assertArrayHasKey(AuthResult::FORM, $wrongPassword->errors);
        $this->assertStringNotContainsString('lena', $wrongPassword->errors[AuthResult::FORM]);
    }

    public function test_empty_fields_get_field_errors_and_keep_the_login(): void
    {
        $result = (new LoginService(self::flow()))->attempt('  lena ', '', false, '');
        $this->assertSame(['pwd'], array_keys($result->errors));
        $this->assertSame('lena', $result->data['login']);
    }

    public function test_errors_from_other_plugins_are_shown_without_markup(): void
    {
        add_filter('authenticate', static fn () => new WP_Error('captcha', '<strong>Error:</strong> Please solve the <a href="#">captcha</a>.'), 30);
        $result = (new LoginService(self::flow()))->attempt('lena', 'correct-horse-battery', false, '');
        $this->assertSame('Please solve the captcha.', $result->errors[AuthResult::FORM]);
    }

    public function test_subscribers_land_on_their_profile_instead_of_an_empty_dashboard(): void
    {
        $subscriber = self::factory()->user->create_and_get(['role' => 'subscriber']);
        $this->assertSame(admin_url('profile.php'), RedirectResolver::forUser('', $subscriber));
    }

    public function test_external_redirects_are_rejected(): void
    {
        $this->assertSame(admin_url(), RedirectResolver::forUser('https://evil.example/steal', $this->user));
        $this->assertSame('', RedirectResolver::sanitizeRequested('https://evil.example/steal'));
        $this->assertSame(home_url('/shop/'), RedirectResolver::forUser(home_url('/shop/'), $this->user));
    }

    public function test_the_core_login_redirect_filter_is_respected(): void
    {
        add_filter('login_redirect', static fn () => home_url('/welcome/'));
        $this->assertSame(home_url('/welcome/'), RedirectResolver::forUser('', $this->user));
    }

    // ------------------------------------------------------------- Password reset

    private function resetService(int $ipMax = 5, int $minLength = 12): PasswordResetService
    {
        $store = new InMemoryAttemptStore();
        return new PasswordResetService(new RateLimiter($store, $ipMax, 900, 900, 86400), new RateLimiter($store, 3, 3600, 3600, 86400), '203.0.113.9', $minLength);
    }

    public function test_reset_request_for_an_unknown_account_looks_like_success_and_sends_nothing(): void
    {
        $result = $this->resetService()->request('ghost@example.org');
        $this->assertTrue($result->success);
        $this->assertFalse(tests_retrieve_phpmailer_instance()->get_sent(0));
    }

    public function test_reset_request_sends_a_link_to_the_portier_screen(): void
    {
        $result = $this->resetService()->request('lena@example.org');
        $this->assertTrue($result->success);
        $this->assertFalse(tests_retrieve_phpmailer_instance()->get_sent(0), 'Not before the response: its timing would tell that the account exists.');
        AfterResponse::run();
        $mail = tests_retrieve_phpmailer_instance()->get_sent(0);
        $this->assertNotFalse($mail);
        $this->assertStringContainsString('action=rp', $mail->body);
        $this->assertStringNotContainsString('wp-login.php?action=rp', $mail->body, 'The link must lead to Portier.');
    }

    public function test_reset_request_needs_input(): void
    {
        $this->assertArrayHasKey('user_login', $this->resetService()->request('  ')->errors);
    }

    public function test_reset_requests_are_rate_limited_per_ip(): void
    {
        $service = $this->resetService(2);
        $service->request('a@example.org');
        $service->request('b@example.org');
        $result = $service->request('c@example.org');
        $this->assertFalse($result->success);
        $this->assertArrayHasKey(AuthResult::FORM, $result->errors);
    }

    public function test_reset_requests_per_address_stop_silently(): void
    {
        $service = $this->resetService(50);
        for ($i = 0; $i < 4; $i++) {
            // Login name and e-mail address of the account share the counter.
            $this->assertTrue($service->request($i % 2 === 0 ? 'lena@example.org' : 'LENA')->success);
        }
        AfterResponse::run();
        $this->assertCount(3, tests_retrieve_phpmailer_instance()->mock_sent, 'Only three mails per hour and account.');
    }

    public function test_reset_errors_of_plugins_are_shown_right_away(): void
    {
        add_action('lostpassword_post', static function (WP_Error $errors): void {
            $errors->add('captcha', 'Please solve the captcha.');
        });
        $result = $this->resetService()->request('lena@example.org');
        $this->assertSame('Please solve the captcha.', $result->errors[AuthResult::FORM]);
    }

    public function test_a_valid_key_resets_the_password_and_ends_all_sessions(): void
    {
        $key = get_password_reset_key($this->user);
        $service = $this->resetService();
        $user = $service->checkKey($key, 'lena');
        $this->assertInstanceOf(WP_User::class, $user);

        WP_Session_Tokens::get_instance($this->user->ID)->create(time() + 3600);
        $result = $service->reset($user, 'a-brand-new-passphrase', 'a-brand-new-passphrase');

        $this->assertTrue($result->success);
        $this->assertTrue(wp_check_password('a-brand-new-passphrase', get_userdata($this->user->ID)->user_pass, $this->user->ID));
        $this->assertSame([], WP_Session_Tokens::get_instance($this->user->ID)->get_all());
        $this->assertInstanceOf(WP_Error::class, $service->checkKey($key, 'lena'), 'A key works only once.');
    }

    public function test_invalid_keys_are_rejected(): void
    {
        $this->assertInstanceOf(WP_Error::class, $this->resetService()->checkKey('forged', 'lena'));
        $this->assertInstanceOf(WP_Error::class, $this->resetService()->checkKey('', ''));
    }

    public function test_new_passwords_are_validated(): void
    {
        $service = $this->resetService(5, 12);
        $this->assertArrayHasKey('pass1', $service->reset($this->user, 'short', 'short')->errors);
        $this->assertArrayHasKey('pass2', $service->reset($this->user, 'long-enough-password', 'different-password')->errors);
        $this->assertArrayHasKey('pass1', $service->reset($this->user, '', '')->errors);
    }

    public function test_plugins_can_add_password_rules(): void
    {
        add_action('validate_password_reset', static function (WP_Error $errors): void {
            $errors->add('weak', 'Too common.');
        });
        $result = $this->resetService()->reset($this->user, 'long-enough-password', 'long-enough-password');
        $this->assertSame('Too common.', $result->errors[AuthResult::FORM]);
    }
}
