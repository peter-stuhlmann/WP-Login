<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Auth\AuthResult;
use Portier\Auth\RegistrationService;
use Portier\Http\AfterResponse;
use Portier\Security\RateLimiter;
use Portier\Tests\Support\InMemoryAttemptStore;
use WP_UnitTestCase;

/**
 * @covers \Portier\Auth\RegistrationService
 */
final class RegistrationServiceTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        reset_phpmailer_instance();
        $this->openRegistration(true);
    }

    private function openRegistration(bool $open): void
    {
        if (is_multisite()) {
            update_site_option('registration', $open ? 'user' : 'none');
        } else {
            update_option('users_can_register', $open ? 1 : 0);
        }
    }

    private function service(int $ipMax = 20): RegistrationService
    {
        return new RegistrationService(new RateLimiter(new InMemoryAttemptStore(), $ipMax, 900, 900, 86400), '203.0.113.9');
    }

    public function test_closed_registration_is_refused(): void
    {
        $this->openRegistration(false);
        $this->assertFalse(RegistrationService::isOpen());
        $result = $this->service()->register('new@example.org', '', true, false);
        $this->assertArrayHasKey(AuthResult::FORM, $result->errors);
    }

    public function test_input_is_validated(): void
    {
        $result = $this->service()->register('not-an-email', str_repeat('x', 101), false, true);
        $this->assertSame(['user_email', 'privacy_consent', 'display_name'], array_keys($result->errors));
        $this->assertSame('not-an-email', $result->data['email'], 'Input is kept for the form.');
    }

    /**
     * @group ms-excluded
     */
    public function test_a_new_account_is_created_with_a_generated_username(): void
    {
        $result = $this->service()->register('Lena.Berger@example.org', 'Lena Berger', true, true);
        $this->assertTrue($result->success);
        $this->assertSame([], tests_retrieve_phpmailer_instance()->mock_sent, 'E-mails go out after the response.');
        AfterResponse::run();

        $user = get_user_by('email', 'lena.berger@example.org');
        $this->assertNotFalse($user);
        $this->assertSame('lena.berger', $user->user_login);
        $this->assertSame('Lena Berger', $user->display_name);
        $this->assertSame(get_option('default_role'), $user->roles[0]);

        $mails = tests_retrieve_phpmailer_instance()->mock_sent;
        $toUser = array_values(array_filter($mails, static fn ($m) => $m['to'][0][0] === 'Lena.Berger@example.org'));
        $this->assertNotEmpty($toUser, 'The new user gets the "set your password" email.');
        $this->assertStringContainsString('action=rp', $toUser[0]['body']);
    }

    /**
     * @group ms-excluded
     */
    public function test_existing_addresses_get_the_same_answer_and_a_friendly_email(): void
    {
        self::factory()->user->create(['user_email' => 'taken@example.org', 'display_name' => 'Taken']);
        $count = count_users()['total_users'];

        $first = $this->service()->register('taken@example.org', '', true, true);
        $second = $this->service()->register('taken@example.org', '', true, true);
        AfterResponse::run();

        $this->assertTrue($first->success, 'Nobody can tell that the address is registered.');
        $this->assertTrue($second->success);
        $this->assertSame($count, count_users()['total_users']);
        $this->assertCount(1, tests_retrieve_phpmailer_instance()->mock_sent, 'At most one notice per day.');
    }

    public function test_registrations_are_rate_limited_per_ip(): void
    {
        $service = $this->service(1);
        $service->register('a@example.org', '', true, false);
        $result = $service->register('b@example.org', '', true, false);
        $this->assertFalse($result->success);
    }

    /**
     * @group ms-required
     */
    public function test_multisite_uses_the_core_signup_flow(): void
    {
        global $wpdb;
        $result = $this->service()->register('network@example.org', 'Net User', true, false);
        $this->assertTrue($result->success);
        $signup = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->signups} WHERE user_email = %s", 'network@example.org'));
        $this->assertNotNull($signup, 'A pending signup (activation email) is created instead of a user.');
        $this->assertFalse(get_user_by('email', 'network@example.org'));
    }
}
