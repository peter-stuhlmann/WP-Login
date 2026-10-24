<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Portier\Auth\UsernameGenerator;

/**
 * @covers \Portier\Auth\UsernameGenerator
 */
final class UsernameGeneratorTest extends TestCase
{
    public function test_uses_the_local_part_of_the_address(): void
    {
        $this->assertSame('lena.berger', UsernameGenerator::fromEmail('Lena.Berger@example.com', fn () => false));
    }

    public function test_strips_characters_that_are_not_allowed(): void
    {
        $this->assertSame('lenaberger', UsernameGenerator::fromEmail('lena+ber"ger@example.com', fn () => false));
    }

    public function test_appends_a_number_until_the_name_is_free(): void
    {
        $taken = ['lena', 'lena2', 'lena3'];
        $this->assertSame('lena4', UsernameGenerator::fromEmail('lena@example.com', fn (string $name) => in_array($name, $taken, true)));
    }

    public function test_never_generates_predictable_admin_names(): void
    {
        $this->assertSame('member-admin', UsernameGenerator::fromEmail('admin@example.com', fn () => false));
        $this->assertSame('member-info', UsernameGenerator::fromEmail('info@example.com', fn () => false));
    }

    public function test_pads_very_short_names(): void
    {
        $this->assertSame('member-jo', UsernameGenerator::fromEmail('jo@example.com', fn () => false));
        $this->assertSame('member', UsernameGenerator::fromEmail('++@example.com', fn () => false));
    }

    public function test_falls_back_to_a_random_suffix_instead_of_looping_forever(): void
    {
        $name = UsernameGenerator::fromEmail('lena@example.com', fn (string $name) => ! str_contains($name, '-'));
        $this->assertMatchesRegularExpression('/^lena-[a-f0-9]{6}$/', $name);
    }
}
