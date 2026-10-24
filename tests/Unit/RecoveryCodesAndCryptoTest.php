<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\Security\Crypto;
use Portier\TwoFactor\RecoveryCodes;

/**
 * @covers \Portier\TwoFactor\RecoveryCodes
 * @covers \Portier\Security\Crypto
 */
final class RecoveryCodesAndCryptoTest extends TestCase
{
    public function test_codes_are_readable_and_unique(): void
    {
        $codes = RecoveryCodes::generate();
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $code, 'No 0/O/1/I/L look-alikes.');
        }
    }

    public function test_codes_match_case_and_format_insensitively(): void
    {
        $hashes = [RecoveryCodes::hash('AAAA-BBBB', 'k'), RecoveryCodes::hash('K7QF-2M9X', 'k')];
        $this->assertSame(1, RecoveryCodes::match('k7qf 2m9x', $hashes, 'k'));
        $this->assertSame(1, RecoveryCodes::match('K7QF2M9X', $hashes, 'k'));
        $this->assertNull(RecoveryCodes::match('K7QF-2M9Y', $hashes, 'k'));
        $this->assertNull(RecoveryCodes::match('K7QF-2M9X', $hashes, 'other-key'), 'Hashes are keyed.');
        $this->assertNull(RecoveryCodes::match('short', $hashes, 'k'));
    }

    public function test_encryption_round_trip(): void
    {
        $crypto = new Crypto(str_repeat('k', 32));
        $sealed = $crypto->encrypt('JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('pc1:', $sealed);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $sealed);
        $this->assertSame('JBSWY3DPEHPK3PXP', $crypto->decrypt($sealed));
        $this->assertNotSame($sealed, $crypto->encrypt('JBSWY3DPEHPK3PXP'), 'Random nonce per encryption.');
    }

    public function test_tampering_and_wrong_keys_are_detected(): void
    {
        $crypto = new Crypto(str_repeat('k', 32));
        $sealed = $crypto->encrypt('secret');
        $tampered = substr($sealed, 0, -2) . (substr($sealed, -2) === 'AA' ? 'AB' : 'AA');
        $this->assertNull($crypto->decrypt($tampered));
        $this->assertNull((new Crypto(str_repeat('x', 32)))->decrypt($sealed));
        $this->assertNull($crypto->decrypt('plain-text'));
    }

    public function test_keys_must_have_32_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Crypto('short');
    }

    public function test_hash_keys_are_purpose_bound(): void
    {
        $crypto = new Crypto(str_repeat('k', 32));
        $this->assertNotSame($crypto->hashKey('recovery'), $crypto->hashKey('device'));
    }
}
