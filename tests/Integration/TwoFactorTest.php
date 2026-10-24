<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Admin\ProfileSection;
use Portier\Auth\PendingLogin;
use Portier\Frontend\TwoFactorScreens;
use Portier\Http\Response;
use Portier\Plugin;
use Portier\Routing\LoginRoute;
use Portier\Settings\Settings;
use Portier\Tests\Support\ScreenRequests;
use Portier\Security\Crypto;
use Portier\TwoFactor\Totp;
use Portier\TwoFactor\TwoFactorGate;
use Portier\TwoFactor\TwoFactorService;
use WP_Error;
use WP_UnitTestCase;
use WP_User;

/**
 * Two-factor authentication end to end: login with code, recovery codes, trusted devices,
 * forced setup, enforcement on core login paths, limits per account, unreadable secrets and the
 * profile section.
 *
 * @covers \Portier\TwoFactor\TwoFactorService
 * @covers \Portier\TwoFactor\CodeCheck
 * @covers \Portier\Auth\RecentLogin
 * @covers \Portier\Security\Lock
 * @covers \Portier\TwoFactor\UserStore
 * @covers \Portier\TwoFactor\TwoFactorGate
 * @covers \Portier\Auth\LoginFlow
 * @covers \Portier\Auth\PendingLogin
 * @covers \Portier\Frontend\TwoFactorScreens
 * @covers \Portier\Admin\ProfileSection
 */
final class TwoFactorTest extends WP_UnitTestCase
{
    use ScreenRequests;

    private WP_User $user;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->jar = [];
        $this->forgetSession();
        reset_phpmailer_instance();
        update_option(Settings::OPTION, ['two_factor_mode' => 'optional', 'trust_device_days' => 30]);
        $this->user = self::factory()->user->create_and_get(['user_login' => 'lena', 'user_email' => 'lena@example.org', 'user_pass' => 'correct-horse-battery', 'role' => 'editor']);
    }

    public function tear_down(): void
    {
        $this->forgetSession();
        parent::tear_down();
    }

    private function service(): TwoFactorService
    {
        return Plugin::instance()->twoFactor();
    }

    /**
     * Turns 2FA on for the test user and returns the secret.
     */
    private function enable(): string
    {
        $secret = $this->service()->store()->pendingSecret($this->user->ID);
        $this->service()->store()->enable($this->user->ID, $secret, Totp::step(time()) - 5);
        return $secret;
    }

    private function currentCode(string $secret): string
    {
        return Totp::code($secret, Totp::step(time()));
    }

    private function logInWithPassword(): Response
    {
        return $this->submit('login', ['log' => 'lena', 'pwd' => 'correct-horse-battery']);
    }

    public function test_without_2fa_the_login_completes_immediately(): void
    {
        $response = $this->logInWithPassword();
        $this->assertSame(admin_url(), $response->redirect);
        $this->assertArrayNotHasKey(PendingLogin::COOKIE, $this->jar);
    }

    public function test_with_2fa_the_password_alone_does_not_log_in(): void
    {
        $this->enable();
        $response = $this->logInWithPassword();

        $this->assertStringContainsString('action=2fa', (string) $response->redirect);
        $this->assertArrayHasKey(PendingLogin::COOKIE, $this->jar);
        $this->assertSame(0, get_current_user_id(), 'Nobody is logged in before the second factor.');
    }

    public function test_correct_code_completes_the_login(): void
    {
        $secret = $this->enable();
        $this->logInWithPassword();

        $page = $this->send('GET', ['action' => '2fa']);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('autocomplete="one-time-code"', $page->body);

        $response = $this->submit('2fa', ['code' => $this->currentCode($secret)]);
        $this->assertSame(303, $response->status);
        $this->assertSame(admin_url(), $response->redirect);
        $this->assertSame($this->user->ID, get_current_user_id());
        $this->assertArrayNotHasKey(PendingLogin::COOKIE, $this->jar, 'The pending login is gone.');
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $secret = $this->enable();
        $code = $this->currentCode($secret);
        $this->logInWithPassword();
        $this->submit('2fa', ['code' => $code]);

        wp_set_current_user(0);
        $this->jar = [];
        $this->logInWithPassword();
        $response = $this->submit('2fa', ['code' => $code]);
        $this->assertSame(422, $response->status);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_wrong_codes_are_limited(): void
    {
        $this->enable();
        $this->logInWithPassword();
        for ($i = 1; $i < PendingLogin::MAX_ATTEMPTS; $i++) {
            $response = $this->submit('2fa', ['code' => '000000']);
            $this->assertSame(422, $response->status);
        }
        $response = $this->submit('2fa', ['code' => '000000']);
        $this->assertStringContainsString('notice=too-many-codes', (string) $response->redirect);
        $this->assertStringContainsString('notice=session-expired', (string) $this->send('GET', ['action' => '2fa'])->redirect);
    }

    public function test_wrong_codes_are_limited_per_account_across_logins(): void
    {
        $secret = $this->enable();
        $codes = $this->service()->store()->regenerateRecoveryCodes($this->user->ID);

        // Logging in again gives a new pending login – but not new attempts for the account.
        for ($round = 0; $round < 2; $round++) {
            $this->jar = [];
            $this->logInWithPassword();
            for ($i = 0; $i < 4; $i++) {
                $this->assertSame(422, $this->submit('2fa', ['code' => '000000'])->status);
            }
        }
        $this->jar = [];
        $this->logInWithPassword();
        $this->assertSame(422, $this->submit('recovery', ['recovery_code' => 'AAAA-AAAA'])->status, 'Recovery codes count, too.');
        $paused = $this->submit('2fa', ['code' => '000000']);
        $this->assertSame(422, $paused->status);
        $this->assertStringContainsString('Too many failed attempts', $paused->body);

        // Paused: not even the right codes are checked.
        $this->assertStringContainsString('Too many failed attempts', $this->submit('2fa', ['code' => $this->currentCode($secret)])->body);
        $this->assertStringContainsString('Too many failed attempts', $this->submit('recovery', ['recovery_code' => $codes[0]])->body);
        $this->assertSame(0, get_current_user_id());
        $this->assertSame(10, $this->service()->store()->recoveryCodesLeft($this->user->ID), 'The recovery code was not used up.');

        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertNotFalse($mail, 'The owner is told: somebody knows the password.');
        $this->assertSame('lena@example.org', $mail->to[0][0]);
        $this->assertStringContainsString('wrong two-factor codes', $mail->body);
    }

    public function test_a_correct_code_clears_the_count(): void
    {
        $secret = $this->enable();
        $this->logInWithPassword();
        for ($i = 0; $i < 4; $i++) {
            $this->submit('2fa', ['code' => '000000']);
        }
        $this->submit('2fa', ['code' => $this->currentCode($secret)]);
        $this->assertSame($this->user->ID, get_current_user_id());

        wp_set_current_user(0);
        $this->jar = [];
        $this->logInWithPassword();
        for ($i = 0; $i < 4; $i++) {
            $this->assertStringNotContainsString('Too many failed attempts', $this->submit('2fa', ['code' => '000000'])->body);
        }
    }

    public function test_an_unreadable_secret_keeps_2fa_required(): void
    {
        $this->enable();
        // The salts or PORTIER_ENCRYPTION_KEY changed: the stored secret cannot be decrypted.
        $other = new Crypto(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        update_user_meta($this->user->ID, 'portier_2fa_secret', $other->encrypt(Totp::generateSecret()));

        $this->assertStringContainsString('action=2fa', (string) $this->logInWithPassword()->redirect, 'Fails closed: no login with the password alone.');
        $this->assertInstanceOf(WP_Error::class, wp_authenticate('lena', 'correct-horse-battery'));

        $page = $this->send('GET', ['action' => '2fa']);
        $this->assertStringContainsString('can no longer be read', $page->body);
        $this->assertSame(422, $this->submit('2fa', ['code' => '123456'])->status);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_application_passwords_are_not_asked_for_a_second_factor(): void
    {
        $this->enable();
        $gate = new TwoFactorGate($this->service(), new LoginRoute('login', true));
        $other = self::factory()->user->create_and_get(['role' => 'editor']);
        $this->service()->store()->enable($other->ID, Totp::generateSecret(), 0);

        $gate->rememberApplicationPassword($this->user);
        $this->assertSame($this->user, $gate->enforce($this->user));
        $this->assertInstanceOf(WP_Error::class, $gate->enforce($other), 'Only the account the application password belongs to.');
    }

    public function test_recovery_codes_work_once(): void
    {
        $this->enable();
        $codes = $this->service()->store()->regenerateRecoveryCodes($this->user->ID);

        $this->logInWithPassword();
        $response = $this->submit('recovery', ['recovery_code' => strtolower($codes[3])]);
        $this->assertSame($this->user->ID, get_current_user_id());
        $this->assertSame(9, $this->service()->store()->recoveryCodesLeft($this->user->ID));

        wp_set_current_user(0);
        $this->jar = [];
        $this->logInWithPassword();
        $this->assertSame(422, $this->submit('recovery', ['recovery_code' => $codes[3]])->status, 'Used codes are gone.');
    }

    public function test_trusted_devices_skip_the_second_factor(): void
    {
        $secret = $this->enable();
        $this->logInWithPassword();
        $this->submit('2fa', ['code' => $this->currentCode($secret), 'trust_device' => '1']);
        $this->assertArrayHasKey(TwoFactorService::DEVICE_COOKIE, $this->jar);

        wp_set_current_user(0);
        $response = $this->logInWithPassword();
        $this->assertSame(admin_url(), $response->redirect, 'The trusted device logs in with the password only.');

        // A password reset forgets all devices.
        reset_password($this->user, 'correct-horse-battery');
        wp_set_current_user(0);
        $this->assertStringContainsString('action=2fa', (string) $this->logInWithPassword()->redirect);
    }

    public function test_a_password_change_in_the_profile_forgets_trusted_devices(): void
    {
        $secret = $this->enable();
        $this->logInWithPassword();
        $this->submit('2fa', ['code' => $this->currentCode($secret), 'trust_device' => '1']);

        wp_update_user(['ID' => $this->user->ID, 'user_pass' => 'correct-horse-battery']);
        wp_set_current_user(0);
        $this->assertStringContainsString('action=2fa', (string) $this->logInWithPassword()->redirect);

        // Other profile changes keep the devices.
        $this->submit('2fa', ['code' => Totp::code($secret, Totp::step(time()) + 1), 'trust_device' => '1']);
        wp_update_user(['ID' => $this->user->ID, 'display_name' => 'Lena B.']);
        wp_set_current_user(0);
        $this->assertSame(admin_url(), $this->logInWithPassword()->redirect);
    }

    public function test_required_roles_are_guided_through_setup(): void
    {
        update_option(Settings::OPTION, ['two_factor_mode' => 'required', 'two_factor_roles' => ['editor']]);

        $this->assertStringContainsString('action=2fa-setup', (string) $this->logInWithPassword()->redirect);
        $setup = $this->send('GET', ['action' => '2fa-setup']);
        $this->assertStringContainsString('otpauth://totp/', $setup->body);

        $secret = $this->service()->store()->pendingSecret($this->user->ID);
        $this->assertSame(422, $this->submit('2fa-setup', ['code' => '000000'])->status);
        $toCodes = $this->submit('2fa-setup', ['code' => $this->currentCode($secret)]);
        $this->assertStringContainsString('action=recovery-codes', (string) $toCodes->redirect);

        $codesPage = $this->send('GET', ['action' => 'recovery-codes']);
        $this->assertSame(10, preg_match_all('#<ul class="portier-codes".*?</ul>#s', $codesPage->body, $m) ? substr_count($m[0][0], '<li>') : 0);
        $this->assertSame(422, $this->submit('recovery-codes', [])->status, 'Saving the codes must be confirmed.');

        $done = $this->submit('recovery-codes', ['codes_saved' => '1']);
        $this->assertSame(admin_url(), $done->redirect);
        $this->assertSame($this->user->ID, get_current_user_id());
        $this->assertTrue($this->service()->store()->isEnabled($this->user->ID));
    }

    public function test_core_login_paths_cannot_skip_the_second_factor(): void
    {
        $this->enable();
        $viaCore = wp_authenticate('lena', 'correct-horse-battery');
        $this->assertInstanceOf(WP_Error::class, $viaCore);
        $this->assertSame(TwoFactorGate::ERROR_CODE, $viaCore->get_error_code());

        $firstFactor = TwoFactorGate::firstFactor(static fn () => wp_authenticate('lena', 'correct-horse-battery'));
        $this->assertInstanceOf(WP_User::class, $firstFactor, "Portier's own flow only checks the first factor.");
    }

    public function test_mode_off_disables_every_prompt(): void
    {
        $this->enable();
        update_option(Settings::OPTION, ['two_factor_mode' => 'off']);
        $this->assertSame(admin_url(), $this->logInWithPassword()->redirect);
        $this->assertInstanceOf(WP_User::class, wp_authenticate('lena', 'correct-horse-battery'));
    }

    public function test_secrets_are_stored_encrypted(): void
    {
        $secret = $this->enable();
        $raw = (string) get_user_meta($this->user->ID, 'portier_2fa_secret', true);
        $this->assertStringStartsWith('pc1:', $raw);
        $this->assertStringNotContainsString($secret, $raw);
    }

    public function test_setup_from_the_profile_needs_a_valid_nonce(): void
    {
        $this->logInSession($this->user->ID);
        $this->assertStringContainsString('notice=session-expired', (string) $this->send('GET', ['action' => '2fa-setup'])->redirect);

        $nonce = wp_create_nonce(TwoFactorScreens::NONCE_SETUP);
        $page = $this->send('GET', ['action' => '2fa-setup', '_wpnonce' => $nonce]);
        $this->assertSame(200, $page->status);

        $secret = $this->service()->store()->pendingSecret($this->user->ID);
        $response = $this->send('POST', ['action' => '2fa-setup', '_wpnonce' => $nonce], ['code' => $this->currentCode($secret), 'portier_token' => $this->csrf('2fa-setup')]);
        $this->assertStringContainsString('action=recovery-codes', (string) $response->redirect);
        $this->assertTrue($this->service()->store()->isEnabled($this->user->ID));
    }

    public function test_changes_from_the_profile_need_a_recent_login(): void
    {
        $this->logInSession($this->user->ID, 20 * MINUTE_IN_SECONDS);
        $profile = admin_url('profile.php') . '#portier-2fa';

        $setup = $this->send('GET', ['action' => '2fa-setup', '_wpnonce' => wp_create_nonce(TwoFactorScreens::NONCE_SETUP)]);
        $this->assertStringContainsString('reauth=1', (string) $setup->redirect);
        $this->assertStringContainsString('redirect_to=' . rawurlencode($profile), (string) $setup->redirect);

        $this->enable();
        $before = get_user_meta($this->user->ID, 'portier_2fa_recovery', true);
        $codes = $this->send('GET', ['action' => 'recovery-codes', 'regenerate' => '1', '_wpnonce' => wp_create_nonce(TwoFactorScreens::NONCE_CODES)]);
        $this->assertStringContainsString('reauth=1', (string) $codes->redirect);
        $this->assertSame($before, get_user_meta($this->user->ID, 'portier_2fa_recovery', true), 'No new codes for an old session.');

        // Right after logging in again it works.
        $this->logInSession($this->user->ID);
        $fresh = $this->send('GET', ['action' => 'recovery-codes', 'regenerate' => '1', '_wpnonce' => wp_create_nonce(TwoFactorScreens::NONCE_CODES)]);
        $this->assertStringNotContainsString('reauth=1', (string) $fresh->redirect);
        $this->assertNotSame($before, get_user_meta($this->user->ID, 'portier_2fa_recovery', true));
    }

    public function test_turning_2fa_off_in_the_profile_requires_the_password(): void
    {
        $this->enable();
        wp_set_current_user($this->user->ID);
        $profile = new ProfileSection($this->service(), new LoginRoute('login', true));

        $_POST = ['portier_2fa_disable' => '1', 'portier_2fa_password' => 'wrong', '_portier_2fa_nonce' => wp_create_nonce('portier-2fa-profile')];
        $errors = new WP_Error();
        $profile->validate($errors, true, $this->user);
        $profile->save($this->user->ID);
        $this->assertTrue($errors->has_errors());
        $this->assertTrue($this->service()->store()->isEnabled($this->user->ID), 'A wrong password keeps 2FA on.');

        $_POST['portier_2fa_password'] = 'correct-horse-battery';
        $errors = new WP_Error();
        $profile->validate($errors, true, $this->user);
        $profile->save($this->user->ID);
        $this->assertFalse($errors->has_errors());
        $this->assertFalse($this->service()->store()->isEnabled($this->user->ID));
        $_POST = [];
    }

    public function test_administrators_can_reset_2fa_of_other_users(): void
    {
        $this->enable();
        $admin = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        $profile = new ProfileSection($this->service(), new LoginRoute('login', true));

        $_POST = ['portier_2fa_reset' => '1', '_portier_2fa_nonce' => wp_create_nonce('portier-2fa-profile')];
        $profile->save($this->user->ID);
        $_POST = [];
        $this->assertFalse($this->service()->store()->isEnabled($this->user->ID));
    }
}
