<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\Routing\CoreLogin;
use Portier\Routing\CoreLoginGuard;

/**
 * @covers \Portier\Routing\CoreLogin
 * @covers \Portier\Routing\CoreLoginGuard::decide
 */
final class CoreLoginTest extends TestCase
{
    /**
     * @dataProvider actions
     *
     * @param array<string, string> $query
     */
    public function test_maps_core_actions(array $query, ?string $expected): void
    {
        $this->assertSame($expected, CoreLogin::portierAction($query));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: ?string}>
     */
    public static function actions(): array
    {
        return [
            'default' => [[], 'login'],
            'login' => [['action' => 'login'], 'login'],
            'lost password' => [['action' => 'lostpassword'], 'lostpassword'],
            'lost password alias' => [['action' => 'retrievepassword'], 'lostpassword'],
            'reset' => [['action' => 'rp'], 'rp'],
            'reset alias' => [['action' => 'resetpass'], 'rp'],
            'register' => [['action' => 'register'], 'register'],
            'check email' => [['action' => 'checkemail'], 'checkemail'],
            'unknown behaves like login' => [['action' => 'whatever'], 'login'],
            'logout stays in core' => [['action' => 'logout'], null],
            'password protected posts stay in core' => [['action' => 'postpass'], null],
            'privacy requests stay in core' => [['action' => 'confirmaction'], null],
            'admin email check stays in core' => [['action' => 'confirm_admin_email'], null],
            'interim login stays in core' => [['interim-login' => '1'], null],
        ];
    }

    public function test_guard_lets_pass_through_actions_through_in_every_mode(): void
    {
        foreach (['redirect', 'block'] as $behavior) {
            $this->assertSame(CoreLoginGuard::ALLOW, CoreLoginGuard::decide('GET', ['action' => 'logout'], $behavior));
        }
    }

    public function test_guard_redirects_login_screens(): void
    {
        $this->assertSame(CoreLoginGuard::REDIRECT, CoreLoginGuard::decide('GET', [], 'redirect'));
        $this->assertSame(CoreLoginGuard::REDIRECT, CoreLoginGuard::decide('get', ['action' => 'lostpassword'], 'redirect'));
    }

    public function test_guard_keeps_posts_for_login_widgets_in_redirect_mode(): void
    {
        $this->assertSame(CoreLoginGuard::ALLOW, CoreLoginGuard::decide('POST', [], 'redirect'));
        $this->assertSame(CoreLoginGuard::ALLOW, CoreLoginGuard::decide('POST', ['action' => 'login'], 'redirect'));
    }

    public function test_guard_redirects_other_forms_posted_to_core(): void
    {
        // They would skip Portier's e-mail limits, bot traps and minimum password length.
        foreach (['lostpassword', 'retrievepassword', 'register', 'rp', 'resetpass'] as $action) {
            $this->assertSame(CoreLoginGuard::REDIRECT, CoreLoginGuard::decide('POST', ['action' => $action], 'redirect'), $action);
        }
    }

    public function test_guard_blocks_everything_but_pass_through_in_block_mode(): void
    {
        $this->assertSame(CoreLoginGuard::BLOCK, CoreLoginGuard::decide('GET', [], 'block'));
        $this->assertSame(CoreLoginGuard::BLOCK, CoreLoginGuard::decide('POST', [], 'block'));
    }
}
