<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Auth\PendingLogin;
use Portier\Passkeys\ChallengeStore;
use Portier\Passkeys\CoseKey;
use Portier\Passkeys\CredentialStore;
use Portier\Passkeys\PasskeyException;
use Portier\Plugin;
use Portier\Settings\Settings;
use Portier\Tests\Support\FakeAuthenticator;
use Portier\Tests\Support\ScreenRequests;
use Portier\TwoFactor\Totp;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WP_User;

/**
 * Passkeys end to end: adding one in the profile, logging in on the passkey screen (options →
 * device → verification), single-use challenges bound to the browser, second factor replaced,
 * REST permissions and the login screen's methods in the admin's order.
 *
 * @covers \Portier\Passkeys\PasskeyService
 * @covers \Portier\Passkeys\ChallengeStore
 * @covers \Portier\Passkeys\CredentialStore
 * @covers \Portier\Passkeys\PasskeyRestController
 * @covers \Portier\Frontend\PasskeyScreens
 * @covers \Portier\Frontend\MethodOrder
 * @covers \Portier\Admin\PasskeyProfileSection
 */
final class PasskeyTest extends WP_UnitTestCase
{
    use ScreenRequests;

    private const ORIGIN = 'https://example.org';
    private const RP = 'example.org';

    private WP_User $user;

    public function set_up(): void
    {
        parent::set_up();
        // WebAuthn needs a secure context: the test site runs on HTTPS.
        update_option('home', self::ORIGIN);
        update_option('siteurl', self::ORIGIN);
        $this->set_permalink_structure('/%postname%/');
        $this->jar = [];
        $this->forgetSession();
        reset_phpmailer_instance();
        update_option(Settings::OPTION, ['passkeys' => true]);
        $this->user = self::factory()->user->create_and_get(['user_login' => 'lena', 'user_email' => 'lena@example.org', 'role' => 'editor']);
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        $this->forgetSession();
        parent::tear_down();
    }

    /**
     * Adds a passkey to the user's account like the profile does.
     */
    private function enrol(FakeAuthenticator $authenticator, string $name = ''): string
    {
        $service = Plugin::instance()->passkeys();
        $options = $service->registrationOptions($this->user);
        return $service->register($this->user, $authenticator->register($options['challenge'], self::ORIGIN, self::RP), $name, 'Mozilla/5.0 (Macintosh; Mac OS X) Safari/605');
    }

    /**
     * Passkey screen, step 1: options (and the browser binding cookie).
     *
     * @return array<string, mixed>
     */
    private function loginOptions(): array
    {
        $response = $this->send('POST', ['action' => 'passkey'], ['portier_step' => 'options']);
        $this->assertSame(200, $response->status, $response->body);
        return json_decode($response->body, true)['options'];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function verify(array $credential, array $extra = []): array
    {
        $response = $this->send('POST', ['action' => 'passkey'], ['portier_step' => 'verify', 'credential' => (string) wp_json_encode($credential)] + $extra);
        return [$response->status, (array) json_decode($response->body, true)];
    }

    private function handle(): string
    {
        return (string) get_user_meta($this->user->ID, CredentialStore::HANDLE, true);
    }

    public function test_a_passkey_is_added_with_a_device_name_and_the_owner_is_told(): void
    {
        $id = $this->enrol(new FakeAuthenticator());
        $passkey = Plugin::instance()->passkeys()->credentials()->find($this->user->ID, $id);
        $this->assertNotNull($passkey);
        $this->assertSame('Safari on macOS', $passkey['name'], 'Default name from the browser.');
        $this->assertSame(CoseKey::ES256, $passkey['key']['alg']);
        $this->assertStringNotContainsString('PRIVATE', $passkey['key']['key'], 'Only the public key is stored.');

        $handle = $this->handle();
        $this->assertSame(43, strlen($handle), 'Random handle instead of the user ID.');
        $this->assertStringNotContainsString((string) $this->user->ID, $handle);

        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertNotFalse($mail);
        $this->assertStringContainsString('Safari on macOS', $mail->body);
        $this->assertStringContainsString('profile.php#portier-passkeys', $mail->body);
    }

    public function test_logging_in_with_a_passkey_skips_password_and_second_factor(): void
    {
        $authenticator = new FakeAuthenticator(CoseKey::EDDSA);
        $this->enrol($authenticator);
        // Even with 2FA set up, the passkey is enough.
        Plugin::instance()->twoFactor()->store()->enable($this->user->ID, Totp::generateSecret(), 0);

        $options = $this->loginOptions();
        $this->assertSame(self::RP, $options['rpId']);
        $this->assertSame([], $options['allowCredentials'], 'Any passkey: the account follows from it.');
        $this->assertArrayHasKey(ChallengeStore::COOKIE, $this->jar, 'The challenge is bound to this browser.');

        $authenticator->counter = 1;
        [$status, $body] = $this->verify($authenticator->login($options['challenge'], self::ORIGIN, self::RP, $this->handle()), ['remember' => '1']);
        $this->assertSame(200, $status, (string) wp_json_encode($body));
        $this->assertStringContainsString('wp-admin', $body['redirect']);
        $this->assertSame($this->user->ID, get_current_user_id());

        $stored = Plugin::instance()->passkeys()->credentials()->find($this->user->ID, $authenticator->id());
        $this->assertSame(1, $stored['count']);
        $this->assertGreaterThan(0, $stored['used']);
    }

    public function test_challenges_work_once_and_only_in_the_browser_that_asked(): void
    {
        $authenticator = new FakeAuthenticator();
        $this->enrol($authenticator);
        $options = $this->loginOptions();
        $credential = $authenticator->login($options['challenge'], self::ORIGIN, self::RP, $this->handle());

        // Another browser (other binding cookie) cannot use it.
        $mine = $this->jar[ChallengeStore::COOKIE];
        $this->jar[ChallengeStore::COOKIE] = str_repeat('a', 48);
        [$status, $body] = $this->verify($credential);
        $this->assertSame(400, $status);
        $this->assertSame(PasskeyException::CHALLENGE, $body['error']);

        // The failed attempt in the wrong browser does not consume it …
        $this->jar[ChallengeStore::COOKIE] = $mine;
        [$status] = $this->verify($credential);
        $this->assertSame(200, $status);

        // … but a successful use does: a replay fails.
        wp_set_current_user(0);
        [$status, $body] = $this->verify($credential);
        $this->assertSame(PasskeyException::CHALLENGE, $body['error']);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_unknown_or_deleted_passkeys_and_foreign_handles_do_not_log_in(): void
    {
        $authenticator = new FakeAuthenticator();
        $id = $this->enrol($authenticator);
        $other = self::factory()->user->create_and_get(['role' => 'administrator']);
        $otherHandle = Plugin::instance()->passkeys()->credentials()->handle($other);

        // The passkey is presented with somebody else's handle.
        $options = $this->loginOptions();
        [$status, $body] = $this->verify($authenticator->login($options['challenge'], self::ORIGIN, self::RP, $otherHandle));
        $this->assertSame(404, $status);
        $this->assertSame(PasskeyException::UNKNOWN, $body['error']);
        $this->assertStringNotContainsString('lena', $body['message'], 'No hint about accounts.');

        // Deleted passkey.
        Plugin::instance()->passkeys()->credentials()->delete($this->user->ID, $id);
        $options = $this->loginOptions();
        [, $body] = $this->verify($authenticator->login($options['challenge'], self::ORIGIN, self::RP, $this->handle()));
        $this->assertSame(PasskeyException::UNKNOWN, $body['error']);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_a_waiting_second_factor_is_completed_by_the_users_passkey(): void
    {
        $authenticator = new FakeAuthenticator();
        $this->enrol($authenticator);
        $pending = (new PendingLogin())->create($this->user->ID, true, admin_url('edit.php'), PendingLogin::STAGE_VERIFY);
        $this->jar[PendingLogin::COOKIE] = $pending;

        // The second-factor screen offers it, and the options name the user's passkeys.
        Plugin::instance()->twoFactor()->store()->enable($this->user->ID, Totp::generateSecret(), 0);
        $this->assertStringContainsString('action=passkey', $this->send('GET', ['action' => '2fa'])->body);
        $options = $this->loginOptions();
        $this->assertSame([$authenticator->id()], array_column($options['allowCredentials'], 'id'));

        [$status, $body] = $this->verify($authenticator->login($options['challenge'], self::ORIGIN, self::RP, $this->handle()));
        $this->assertSame(200, $status);
        $this->assertSame(admin_url('edit.php'), $body['redirect'], 'The original target is kept.');
        $this->assertNull((new PendingLogin())->get($pending), 'The waiting login is closed.');
    }

    public function test_passkeys_need_the_setting_and_https(): void
    {
        update_option(Settings::OPTION, ['passkeys' => false]);
        $response = $this->send('POST', ['action' => 'passkey'], ['portier_step' => 'options']);
        $this->assertSame(403, $response->status);
        $this->assertStringNotContainsString('portier-passkey-button', $this->send('GET')->body);

        update_option(Settings::OPTION, ['passkeys' => true]);
        update_option('home', 'http://example.org');
        $this->assertFalse(Plugin::instance()->passkeys()->enabled(), 'No WebAuthn without a secure context.');
        update_option('home', 'http://localhost');
        $this->assertTrue(Plugin::instance()->passkeys()->enabled(), 'http://localhost is allowed for development.');
    }

    public function test_the_login_screen_offers_passkeys_in_the_admins_order(): void
    {
        $page = $this->send('GET')->body;
        $this->assertStringContainsString('autocomplete="username webauthn"', $page, 'Passkey autofill in the e-mail field.');
        $this->assertMatchesRegularExpression('/class="portier-button portier-button--secondary portier-passkey-button"[^>]*data-portier-passkey[^>]*hidden/s', $page);

        $this->assertSame(
            ['b', 'a', 'c'],
            array_column(\Portier\Frontend\MethodOrder::apply(['a' => ['id' => 'a'], 'b' => ['id' => 'b'], 'c' => ['id' => 'c']], ['b', 'gone']), 'id'),
            'Configured ids first, unknown ids skipped, the rest in default order.'
        );
    }

    public function test_the_profile_lists_passkeys_and_rest_permissions_hold(): void
    {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');

        $id = $this->enrol(new FakeAuthenticator(), 'Work laptop');
        $request = static function (string $method, string $route, array $params = []) use ($wp_rest_server) {
            $request = new WP_REST_Request($method, '/portier/v1/passkeys' . $route);
            foreach ($params as $key => $value) {
                $request->set_param($key, $value);
            }
            return $wp_rest_server->dispatch($request);
        };

        // Somebody else may neither see nor delete the passkeys.
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertSame(403, $request('GET', '', ['user' => $this->user->ID])->get_status());
        $this->assertSame(403, $request('DELETE', '/' . $id, ['user' => $this->user->ID])->get_status());

        // The owner can list and rename.
        wp_set_current_user($this->user->ID);
        $list = $request('GET', '')->get_data()['passkeys'];
        $this->assertSame('Work laptop', $list[0]['name']);
        $this->assertSame('Laptop', $request('POST', '/' . $id, ['name' => '<b>Laptop</b>'])->get_data()['passkeys'][0]['name']);

        ob_start();
        $passkeys = Plugin::instance()->passkeys();
        (new \Portier\Admin\PasskeyProfileSection($passkeys, new \Portier\Passkeys\PasskeyRestController($passkeys, Plugin::instance()->recentLogin())))->render($this->user);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('id="portier-passkeys"', $html);
        $this->assertStringContainsString('Laptop', $html);
        $this->assertStringContainsString('data-portier-passkey-create', $html, 'Own profile: add button.');

        // An administrator can delete it (lost device), but not add one for the user.
        $admin = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        $this->logInSession($admin);
        $this->assertSame([], $request('DELETE', '/' . $id, ['user' => $this->user->ID])->get_data()['passkeys']);
        $this->assertSame(0, Plugin::instance()->passkeys()->credentials()->count($this->user->ID));
        $options = $request('POST', '/options')->get_data()['options'];
        $this->assertNotSame($this->handle(), $options['user']['id'], 'Options are always for the current user.');
    }

    public function test_adding_a_passkey_needs_a_recent_login(): void
    {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
        $dispatch = static fn (string $route, array $params = []) => $wp_rest_server->dispatch(
            (static function () use ($route, $params): WP_REST_Request {
                $request = new WP_REST_Request('POST', '/portier/v1/passkeys' . $route);
                foreach ($params as $key => $value) {
                    $request->set_param($key, $value);
                }
                return $request;
            })()
        );

        $this->logInSession($this->user->ID, 20 * MINUTE_IN_SECONDS);
        foreach (['/options' => [], '' => ['credential' => ['id' => 'x']]] as $route => $params) {
            $response = $dispatch($route, $params);
            $this->assertSame(403, $response->get_status());
            $this->assertSame('portier_reauth_required', $response->get_data()['code']);
            $this->assertStringContainsString('reauth=1', $response->get_data()['data']['reauth_url']);
        }
        $this->assertSame(0, Plugin::instance()->passkeys()->credentials()->count($this->user->ID));

        $this->logInSession($this->user->ID);
        $this->assertSame(200, $dispatch('/options')->get_status(), 'Right after logging in again.');

        // Guests get nothing at all.
        $this->forgetSession();
        wp_set_current_user(0);
        $guest = new WP_REST_Request('GET', '/portier/v1/passkeys');
        $this->assertSame(401, $wp_rest_server->dispatch($guest)->get_status());
    }

    public function test_accounts_that_may_not_log_in_are_refused(): void
    {
        $authenticator = new FakeAuthenticator();
        $this->enrol($authenticator);
        add_filter('portier_passwordless_login_allowed', static fn (bool $allowed, WP_User $user, string $method): bool => $method !== 'passkey', 10, 3);

        $options = $this->loginOptions();
        $authenticator->counter = 1;
        [$status, $body] = $this->verify($authenticator->login($options['challenge'], self::ORIGIN, self::RP, $this->handle()));
        $this->assertSame(400, $status);
        $this->assertSame(PasskeyException::BLOCKED, $body['error']);
        $this->assertSame(0, get_current_user_id());
    }

    public function test_at_most_twenty_passkeys_per_account(): void
    {
        for ($i = 0; $i < CredentialStore::MAX_PER_USER; $i++) {
            $this->enrol(new FakeAuthenticator());
        }
        $this->expectException(PasskeyException::class);
        Plugin::instance()->passkeys()->registrationOptions($this->user);
    }
}
