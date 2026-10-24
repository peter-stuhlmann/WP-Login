<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\TwoFactor\Base32;
use Portier\TwoFactor\Totp;

/**
 * @covers \Portier\TwoFactor\Totp
 * @covers \Portier\TwoFactor\Base32
 */
final class TotpTest extends TestCase
{
    /** RFC 6238 test secret "12345678901234567890" (SHA1). */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @dataProvider rfcVectors
     */
    public function test_matches_the_rfc_6238_test_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code(self::RFC_SECRET, Totp::step($time), 8));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            '59' => [59, '94287082'],
            '1111111109' => [1111111109, '07081804'],
            '1111111111' => [1111111111, '14050471'],
            '1234567890' => [1234567890, '89005924'],
            '2000000000' => [2000000000, '69279037'],
            '20000000000' => [20000000000, '65353130'],
        ];
    }

    public function test_base32_round_trip_and_tolerant_decoding(): void
    {
        $this->assertSame(self::RFC_SECRET, Base32::encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', Base32::decode('gezd gnbv-gy3t qojq gezd gnbv gy3t qojq'));
        $this->assertSame('', Base32::encode(''));
    }

    public function test_base32_rejects_invalid_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Base32::decode('ABC1');
    }

    public function test_generated_secrets_are_160_bit_and_unique(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Base32::decode($secret)));
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function test_verify_accepts_the_current_and_adjacent_steps(): void
    {
        $time = 1_700_000_000;
        $step = Totp::step($time);
        $secret = Totp::generateSecret();
        $this->assertSame($step, Totp::verify($secret, Totp::code($secret, $step), $time));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $time));
        $this->assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $time));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), $time), 'Older codes are rejected.');
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $time = 1_700_000_000;
        $secret = Totp::generateSecret();
        $code = Totp::code($secret, Totp::step($time));
        $used = Totp::verify($secret, $code, $time);
        $this->assertNotNull($used);
        $this->assertNull(Totp::verify($secret, $code, $time, $used), 'Replay must fail.');
    }

    public function test_formatting_is_tolerated_but_wrong_length_is_rejected(): void
    {
        $time = 1_700_000_000;
        $secret = Totp::generateSecret();
        $code = Totp::code($secret, Totp::step($time));
        $this->assertNotNull(Totp::verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), $time));
        $this->assertNull(Totp::verify($secret, '12345', $time));
        $this->assertNull(Totp::verify($secret, '', $time));
    }

    public function test_uri_follows_the_key_uri_format(): void
    {
        $uri = Totp::uri('ABC', 'lena@example.org', 'Nordlicht Studio');
        $this->assertSame('otpauth://totp/Nordlicht%20Studio:lena%40example.org?secret=ABC&issuer=Nordlicht%20Studio&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
