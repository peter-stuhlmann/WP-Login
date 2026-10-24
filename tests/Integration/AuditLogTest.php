<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Audit\AuditLog;
use Portier\Audit\LogFile;
use Portier\Plugin;
use Portier\Settings\Settings;
use Portier\Tests\Support\ScreenRequests;
use Portier\TwoFactor\Totp;
use WP_Error;
use WP_UnitTestCase;
use WP_User;

/**
 * The audit log file: who changed which setting, account security events, logins – one line
 * per event, secrets never written, no forged lines, protected directory, retention.
 *
 * @covers \Portier\Audit\AuditLog
 * @covers \Portier\Audit\AuditSubscriber
 * @covers \Portier\Audit\SettingsDiff
 * @covers \Portier\Audit\LogFile
 */
final class AuditLogTest extends WP_UnitTestCase
{
    use ScreenRequests;

    private string $dir;
    private WP_User $admin;

    public function set_up(): void
    {
        parent::set_up();
        $this->dir = wp_normalize_path(get_temp_dir()) . 'portier-audit-' . wp_generate_password(8, false);
        AuditLog::use(new AuditLog(new LogFile($this->dir)));
        delete_site_transient('portier_log_pruned');
        Plugin::instance()->settings()->flushCache();
        $this->admin = self::factory()->user->create_and_get(['display_name' => 'Ada Admin', 'user_email' => 'ada@example.org', 'role' => 'administrator']);
        wp_set_current_user($this->admin->ID);
        $this->jar = [];
    }

    public function tear_down(): void
    {
        (new LogFile($this->dir))->deleteAll();
        AuditLog::use(null);
        parent::tear_down();
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $lines = [];
        foreach ((new LogFile($this->dir))->files() as $file) {
            array_push($lines, ...array_filter(explode("\n", (string) file_get_contents($file))));
        }
        return $lines;
    }

    private function line(string $event): string
    {
        foreach (array_reverse($this->lines()) as $line) {
            if (str_contains($line, '| ' . $event . ' |')) {
                return $line;
            }
        }
        $this->fail("No {$event} line in:\n" . implode("\n", $this->lines()));
    }

    public function test_a_line_says_when_who_where_and_what(): void
    {
        Plugin::instance()->settings()->update(['passkeys' => false]);
        $line = $this->line('settings.changed');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-]\d{2}:\d{2} \| /',
            $line,
            'Date, time with seconds and time zone offset.'
        );
        $this->assertStringContainsString('Ada Admin <ada@example.org> #' . $this->admin->ID . ' Administrator', $line);
        $this->assertStringEndsWith('| settings.changed | Passkeys disabled', $line);
    }

    public function test_every_setting_is_logged_readably_and_secrets_never(): void
    {
        $settings = Plugin::instance()->settings();
        $settings->update([
            'min_password_length' => 14,
            'login_steps' => 'two',
            'two_factor_roles' => ['administrator', 'editor'],
            'custom_css' => '.portier-title { color: red; }',
            'providers' => ['google' => ['enabled' => true, 'client_id' => 'id-1', 'client_secret' => 'pc1:topsecret-ciphertext']],
        ]);
        $log = implode("\n", $this->lines());
        $this->assertStringContainsString('Minimum password length: 12 → 14', $log);
        $this->assertStringContainsString('Login screen: one step → two steps', $log);
        $this->assertStringContainsString('Two-factor authentication required for: Administrator → Administrator, Editor', $log);
        $this->assertStringContainsString('Custom CSS changed (0 → 30 characters)', $log);
        $this->assertStringContainsString('Login with Google enabled', $log);
        $this->assertStringContainsString('Google: client ID – → id-1', $log);
        $this->assertStringContainsString('Google: client secret changed', $log);
        $this->assertStringNotContainsString('topsecret', $log, 'Secrets never reach the log.');
        $this->assertStringNotContainsString('color: red', $log, 'Nor the CSS itself.');
        $changes = static fn (array $lines): int => count(array_filter($lines, static fn (string $line): bool => str_contains($line, '| settings.changed |')));
        $this->assertSame(7, $changes($this->lines()), 'One line per change.');

        // Saving without changes writes nothing.
        $settings->update([]);
        $this->assertSame(7, $changes($this->lines()));
    }

    public function test_switching_the_log_off_is_its_last_entry(): void
    {
        $settings = Plugin::instance()->settings();
        $this->assertTrue(AuditLog::enabled(), 'On by default.');

        $settings->update(['audit_log' => false, 'min_password_length' => 14]);
        $lines = $this->lines();
        $this->assertStringEndsWith('Audit log disabled', (string) end($lines), 'Switching off is still recorded.');
        $this->assertStringContainsString('Minimum password length: 12 → 14', implode("
", $lines), 'So are changes saved with it.');
        $this->assertFalse(AuditLog::enabled());

        // From now on nothing is written …
        $count = count($lines);
        $settings->update(['passkeys' => false]);
        do_action('wp_login_failed', 'lena', null);
        $this->assertCount($count, $this->lines());

        // … until it is switched on again (which is recorded).
        $settings->update(['audit_log' => true]);
        $this->assertStringEndsWith('Audit log enabled', $this->line('settings.changed'));
        $this->assertCount($count + 1, $this->lines());
    }

    public function test_registration_and_default_role(): void
    {
        if (is_multisite()) {
            update_site_option('registration', 'user');
        } else {
            update_option('users_can_register', 0);
            update_option('users_can_register', 1);
            update_option('default_role', 'author');
            $this->assertStringEndsWith('Role for new accounts: Subscriber → Author', $this->line('registration.role'));
        }
        $this->assertStringEndsWith('Registration allowed', $this->line('registration.changed'));
    }

    public function test_logins_are_logged_with_their_method_and_failures_with_the_reason(): void
    {
        wp_set_current_user(0);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0';
        self::factory()->user->create(['user_login' => 'lena', 'user_email' => 'lena@example.org', 'user_pass' => 'correct-horse-battery', 'role' => 'editor']);

        $this->submit('login', ['log' => 'lena', 'pwd' => 'wrong']);
        $failed = $this->line('login.failed');
        $this->assertStringContainsString('| – |', $failed, 'Nobody is logged in yet.');
        $this->assertStringEndsWith('Login failed for “lena” (wrong login or password)', $failed);
        $this->assertStringContainsString('| 203.0.113.9 · Chrome on Windows |', $failed, 'IP address and browser.');
        $this->assertStringContainsString('| Login page |', $failed);

        $this->submit('login', ['log' => 'lena', 'pwd' => 'correct-horse-battery']);
        $success = $this->line('login.success');
        $this->assertStringContainsString('<lena@example.org>', $success);
        $this->assertStringEndsWith('Logged in with password', $success);
    }

    public function test_account_security_changes_name_the_affected_person(): void
    {
        $lena = self::factory()->user->create_and_get(['display_name' => 'Lena', 'user_email' => 'lena@example.org', 'role' => 'editor']);
        $store = Plugin::instance()->twoFactor()->store();
        $store->enable($lena->ID, Totp::generateSecret(), 0);
        $store->disable($lena->ID);
        do_action('portier_passkey_deleted', $lena->ID, 'iPhone');

        $this->assertStringEndsWith('Two-factor authentication set up – for Lena <lena@example.org> #' . $lena->ID, $this->line('2fa.enabled'));
        $this->assertStringContainsString('Two-factor authentication turned off – for Lena', $this->line('2fa.disabled'));
        $deleted = $this->line('passkey.deleted');
        $this->assertStringContainsString('Ada Admin <ada@example.org>', $deleted, 'Who did it …');
        $this->assertStringEndsWith('Passkey “iPhone” deleted – for Lena <lena@example.org> #' . $lena->ID, $deleted, '… and for whom.');

        // Own changes do not repeat the person.
        wp_set_current_user($lena->ID);
        do_action('portier_passkey_added', $lena->ID, 'Laptop');
        $this->assertStringEndsWith('| passkey.added | Passkey “Laptop” added', $this->line('passkey.added'));
    }

    public function test_entered_values_cannot_forge_lines(): void
    {
        do_action('portier_passkey_added', $this->admin->ID, "Laptop\n2026-01-01 00:00:00 +00:00 | Ada Admin | forged");
        $lines = array_values(array_filter($this->lines(), static fn (string $line): bool => str_contains($line, 'Laptop')));
        $this->assertCount(1, $lines, 'Still one line.');
        $this->assertStringContainsString('Laptop 2026-01-01', $lines[0]);
    }

    public function test_login_names_of_unknown_accounts_are_not_written(): void
    {
        // People sometimes type their password into the name field.
        do_action('wp_login_failed', 'my-secret-password', new WP_Error('invalid_username'));
        $this->assertStringEndsWith('Login failed for an unknown account (wrong login or password)', $this->line('login.failed'));
        $this->assertStringNotContainsString('my-secret-password', implode("\n", $this->lines()));

        do_action('portier_login_locked', 'my-secret-password', 300);
        $this->assertStringEndsWith('Logins paused for 5 minutes after too many failed attempts (last attempt: unknown account)', $this->line('login.locked'));
    }

    public function test_paused_second_factors_are_logged(): void
    {
        do_action('portier_2fa_locked', $this->admin->ID, 900);
        $this->assertStringEndsWith('Two-factor codes paused for 15 minutes after too many wrong codes – the password is probably known', $this->line('2fa.locked'));
    }

    public function test_the_directory_is_closed_and_old_files_are_removed(): void
    {
        $file = new LogFile($this->dir);
        $now = time();
        $this->assertMatchesRegularExpression('/portier-\d{4}-\d{2}-[a-f0-9]{16}\.log$/', $file->path($now), 'Unguessable name.');

        // A file from long ago (beyond the 12 months) …
        $old = str_replace(wp_date('Y-m', $now), '2020-01', $file->path($now));
        wp_mkdir_p($this->dir);
        file_put_contents($old, "old\n");
        delete_site_transient('portier_log_pruned');
        AuditLog::instance()->record('test', ['one']);
        $this->assertFileDoesNotExist($old, '… is deleted.');
        $this->assertFileExists($file->path($now));

        foreach (['.htaccess', 'web.config', 'index.php'] as $guard) {
            $this->assertFileExists($this->dir . '/' . $guard);
        }
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($this->dir . '/.htaccess'));

        $file->deleteAll();
        $this->assertDirectoryDoesNotExist($this->dir, 'Uninstall removes everything.');
    }
}
