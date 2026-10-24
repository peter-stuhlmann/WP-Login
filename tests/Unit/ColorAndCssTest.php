<?php

declare(strict_types=1);

namespace Portier\Tests\Unit;

use Portier\Design\Color;
use Portier\Design\CssSanitizer;
use WP_UnitTestCase;

/**
 * Colour helper (WCAG contrast) and the custom CSS sanitiser.
 *
 * @covers \Portier\Design\Color
 * @covers \Portier\Design\CssSanitizer
 */
final class ColorAndCssTest extends WP_UnitTestCase
{
    public function test_hex_colours_are_normalised_or_rejected(): void
    {
        $this->assertSame('#6230e0', Color::hex(' #6230E0 '));
        $this->assertSame('#aabbcc', Color::hex('#abc'));
        $this->assertSame('', Color::hex('red'));
        $this->assertSame('', Color::hex('#12345'));
        $this->assertSame('', Color::hex('#123456; background:url(x)'));
    }

    public function test_contrast_follows_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Color::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, Color::contrast('#777777', '#777777'), 0.01);
        // Reference value from the WCAG contrast checker: #767676 on white is 4.54:1.
        $this->assertEqualsWithDelta(4.54, Color::contrast('#767676', '#ffffff'), 0.01);
    }

    public function test_text_on_buttons_always_picks_the_better_contrast(): void
    {
        $this->assertSame('#ffffff', Color::onColor('#4f46e5'));
        $this->assertSame('#0f0e17', Color::onColor('#c8f55a'));
        foreach (['#ff5fa2', '#0b7a5a', '#ffe14d', '#1d1a15', '#ffffff'] as $background) {
            $this->assertGreaterThanOrEqual(4.5, Color::contrast(Color::onColor($background), $background), $background);
        }
    }

    public function test_hover_shades_stay_close(): void
    {
        $this->assertNotSame('#4f46e5', Color::hover('#4f46e5'));
        $this->assertLessThan(1.6, Color::contrast(Color::hover('#4f46e5'), '#4f46e5'));
        $this->assertSame('#808080', Color::mix('#000000', '#ffffff', 0.5));
    }

    /**
     * @dataProvider dangerousCss
     */
    public function test_dangerous_css_is_removed(string $css, string $mustNotContain): void
    {
        $this->assertStringNotContainsStringIgnoringCase($mustNotContain, CssSanitizer::clean($css));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dangerousCss(): array
    {
        return [
            'break out of style' => ['a{}</style><script>alert(1)</script>', '<script'],
            'closing style tag only' => ['a{} </style >', '</style'],
            'import' => ['@import url("https://evil.test/x.css"); a{color:red}', '@import'],
            'javascript url' => ['a{background:url(javascript:alert(1))}', 'javascript:'],
            'html data url' => ['a{background:url("data:text/html,<b>x</b>")}', 'data:text/html'],
            'remote http' => ['a{background:url(http://evil.test/x.png)}', 'http://evil'],
            'expression' => ['a{width:expression(alert(1))}', 'expression('],
            'behavior' => ['a{behavior:url(x.htc)}', 'behavior:'],
            'moz binding' => ['a{-moz-binding:url(x.xml#xss)}', '-moz-binding:'],
        ];
    }

    public function test_normal_css_survives(): void
    {
        $css = ".portier-card > .portier-title { color: #123456; background: url(\"https://cdn.example.org/a.png\") center/cover; }\n"
            . '.portier-button{background:url(data:image/svg+xml;base64,AAAA)}';
        $this->assertSame($css, CssSanitizer::clean($css));
        $this->assertSame(CssSanitizer::MAX_LENGTH, mb_strlen(CssSanitizer::clean(str_repeat('a', CssSanitizer::MAX_LENGTH + 50))));
    }
}
