<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\Security\FormToken;

/**
 * @covers \Portier\Security\FormToken
 */
final class FormTokenTest extends TestCase
{
    private const COOKIE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_a_token_verifies_for_the_same_cookie_and_action(): void
    {
        $token = new FormToken('secret', self::COOKIE);
        $this->assertTrue($token->verify('login', $token->create('login')));
    }

    public function test_a_token_is_bound_to_its_action(): void
    {
        $token = new FormToken('secret', self::COOKIE);
        $this->assertFalse($token->verify('register', $token->create('login')));
    }

    public function test_a_token_is_bound_to_the_cookie(): void
    {
        $issued = (new FormToken('secret', self::COOKIE))->create('login');
        $other = new FormToken('secret', str_repeat('b', 64));
        $this->assertFalse($other->verify('login', $issued), 'An attacker without the victim\'s cookie cannot forge a token.');
    }

    public function test_verification_fails_without_cookie(): void
    {
        $token = new FormToken('secret', null);
        $this->assertFalse($token->hasCookie());
        $this->assertFalse($token->verify('login', 'anything'));
    }

    public function test_a_missing_or_malformed_cookie_is_regenerated_and_flagged(): void
    {
        foreach ([null, 'short', str_repeat('z', 64)] as $cookie) {
            $token = new FormToken('secret', $cookie);
            $value = $token->cookieValue();
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $value);
            $this->assertTrue($token->needsCookie());
        }
    }

    public function test_an_existing_cookie_is_kept(): void
    {
        $token = new FormToken('secret', self::COOKIE);
        $token->create('login');
        $this->assertSame(self::COOKIE, $token->cookieValue());
        $this->assertFalse($token->needsCookie());
    }

    public function test_empty_tokens_are_rejected(): void
    {
        $this->assertFalse((new FormToken('secret', self::COOKIE))->verify('login', ''));
    }

    public function test_timestamp_age_is_measured(): void
    {
        $token = new FormToken('secret', self::COOKIE);
        $stamp = $token->timestamp('register', 1000);
        $this->assertSame(5, $token->timestampAge('register', $stamp, 1005));
    }

    public function test_forged_or_foreign_timestamps_are_rejected(): void
    {
        $token = new FormToken('secret', self::COOKIE);
        $this->assertNull($token->timestampAge('register', '1000.deadbeef', 1005));
        $this->assertNull($token->timestampAge('register', 'garbage', 1005));
        $this->assertNull($token->timestampAge('login', $token->timestamp('register', 1000), 1005), 'Timestamps are bound to their action.');
    }
}
