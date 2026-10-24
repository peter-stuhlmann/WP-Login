<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Security\LoginThrottle;
use Portier\Security\RateLimiter;
use Portier\Security\TransientAttemptStore;
use WP_Error;
use WP_UnitTestCase;
use WP_User;

/**
 * The throttle protects every authentication path because it hooks into core authentication.
 *
 * @covers \Portier\Security\LoginThrottle
 * @covers \Portier\Security\TransientAttemptStore
 */
final class LoginThrottleTest extends WP_UnitTestCase
{
    private WP_User $user;
    private LoginThrottle $throttle;

    public function set_up(): void
    {
        parent::set_up();
        $this->user = self::factory()->user->create_and_get(['user_login' => 'lena', 'user_email' => 'lena@example.org', 'user_pass' => 'correct-horse-battery']);

        // Replace the plugin's throttle with one using tiny limits for this test.
        remove_all_filters('authenticate');
        remove_all_actions('wp_login_failed');
        remove_all_actions('wp_login');
        add_filter('authenticate', 'wp_authenticate_username_password', 20, 3);
        add_filter('authenticate', 'wp_authenticate_email_password', 20, 3);

        $store = new TransientAttemptStore();
        $this->throttle = new LoginThrottle(
            new RateLimiter($store, 3, 900, 300, 86400),
            new RateLimiter($store, 5, 3600, 900, 3600),
            '203.0.113.9'
        );
        $this->throttle->register();
    }

    public function test_correct_credentials_work_normally(): void
    {
        $this->assertInstanceOf(WP_User::class, wp_authenticate('lena', 'correct-horse-battery'));
    }

    public function test_too_many_failures_lock_the_ip_even_for_the_correct_password(): void
    {
        for ($i = 0; $i < 3; $i++) {
            wp_authenticate('lena', 'wrong');
        }
        $result = wp_authenticate('lena', 'correct-horse-battery');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(LoginThrottle::ERROR_CODE, $result->get_error_code());
        $this->assertGreaterThan(0, $result->get_error_data()['retry_after']);
    }

    public function test_a_success_clears_the_account_counter(): void
    {
        $store = new TransientAttemptStore();
        $elsewhere = static fn (int $i): LoginThrottle => new LoginThrottle(new RateLimiter($store, 100, 900, 300, 86400), new RateLimiter($store, 5, 3600, 900, 3600), '198.51.100.' . $i);
        for ($i = 0; $i < 4; $i++) {
            $elsewhere($i)->recordFailure('lena');
        }
        do_action('wp_login', 'lena', $this->user); // Fired by wp_signon() on success.
        for ($i = 0; $i < 4; $i++) {
            $elsewhere($i)->recordFailure('lena');
        }
        $this->assertSame(0, $this->throttle->lockedFor('lena'));
    }

    public function test_a_success_does_not_reset_the_ip_counter(): void
    {
        // Otherwise a login to an own account between guesses would reset it.
        wp_authenticate('lena', 'wrong');
        wp_authenticate('mallory', 'wrong');
        do_action('wp_login', 'mallory', $this->user);
        wp_authenticate('lena', 'wrong');
        $result = wp_authenticate('lena', 'correct-horse-battery');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(LoginThrottle::ERROR_CODE, $result->get_error_code());
    }

    public function test_login_and_email_share_one_account_counter(): void
    {
        $this->assertSame(0, $this->throttle->lockedFor('lena'));
        $store = new TransientAttemptStore();
        // Different IPs, same account: the account limiter (5) kicks in.
        for ($i = 0; $i < 5; $i++) {
            $throttle = new LoginThrottle(new RateLimiter($store, 100, 900, 300, 86400), new RateLimiter($store, 5, 3600, 900, 3600), '198.51.100.' . $i);
            $throttle->recordFailure($i % 2 === 0 ? 'lena' : 'LENA@example.org');
        }
        $this->assertGreaterThan(0, $this->throttle->lockedFor('lena@example.org'));
    }

    public function test_blank_requests_are_not_throttled(): void
    {
        $this->assertNull($this->throttle->enforce(null, ''));
    }

    public function test_rejections_because_of_the_lock_are_not_counted_again(): void
    {
        $this->throttle->recordFailure('lena', new WP_Error(LoginThrottle::ERROR_CODE));
        $this->assertSame(0, $this->throttle->lockedFor('lena'));
    }
}
