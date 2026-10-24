<?php

declare(strict_types=1);

namespace Portier\Design;

use Portier\Settings\Settings;

/**
 * The design of the login page as configured: template, effective colour scheme, fonts, panel
 * texts and the "Farben & Form" overrides. Everything the layout and the <head> need, computed in
 * one place.
 */
final class Design
{
    /**
     * @param 'light'|'dark'|'auto' $scheme Effective scheme (templates without a dark variant are fixed).
     * @param array{primary: string, accent: string, background: string} $colors Custom colours ('' = template).
     * @param int $radius Custom corner radius in px (-1 = template).
     * @param array{url: string, mobile: string, dark: string, x: int, y: int, overlay: int, onMobile: bool}|null $photo
     *   Background image (null = none).
     */
    private function __construct(
        public readonly string $template,
        public readonly string $scheme,
        public readonly string $uiFont,
        public readonly string $displayFont,
        public readonly string $headline,
        public readonly string $text,
        public readonly bool $panel,
        public readonly array $colors,
        public readonly int $radius,
        public readonly string $customCss,
        public readonly ?array $photo = null,
    ) {
    }

    public static function fromSettings(Settings $settings): self
    {
        $id = Templates::id($settings->string('template'));
        $template = Templates::all()[$id];
        $scheme = match ($template['dark']) {
            'both' => in_array($settings->string('color_scheme'), ['light', 'dark'], true) ? $settings->string('color_scheme') : 'auto',
            'dark' => 'dark',
            default => 'light',
        };
        $defaults = Templates::defaultTexts($id, (string) get_bloginfo('name'), (string) get_bloginfo('description'));
        $headline = $settings->string('design_headline');
        $text = $settings->string('design_text');
        $font = $settings->string('custom_font');
        $font = Fonts::exists($font) ? $font : '';

        return new self(
            $id,
            $scheme,
            $font !== '' ? $font : $template['ui'],
            $font !== '' ? $font : $template['display'],
            $headline !== '' ? $headline : $defaults['headline'],
            $text !== '' ? $text : $defaults['text'],
            $template['panel'],
            [
                'primary' => Color::hex($settings->string('custom_primary')),
                'accent' => Color::hex($settings->string('custom_accent')),
                'background' => Color::hex($settings->string('custom_background')),
            ],
            max(-1, min(32, $settings->int('custom_radius'))),
            $settings->string('custom_css'),
            self::photo($settings, $template['dark'] === 'both'),
        );
    }

    /**
     * Background image from the media library – only real image attachments count.
     *
     * @return array{url: string, mobile: string, dark: string, x: int, y: int, overlay: int, onMobile: bool}|null
     */
    private static function photo(Settings $settings, bool $hasDarkVariant): ?array
    {
        $id = $settings->int('bg_image');
        if ($id <= 0 || ! wp_attachment_is_image($id)) {
            return null;
        }
        $darkId = $settings->int('bg_image_dark');
        $dark = $hasDarkVariant && $settings->bool('bg_dark') && $darkId > 0 && wp_attachment_is_image($darkId)
            ? (string) wp_get_attachment_image_url($darkId, 'full')
            : '';
        return [
            'url' => (string) wp_get_attachment_image_url($id, 'full'),
            // Phones get the "large" size (smaller download).
            'mobile' => (string) (wp_get_attachment_image_url($id, 'large') ?: wp_get_attachment_image_url($id, 'full')),
            'dark' => $dark,
            'x' => max(0, min(100, $settings->int('bg_focus_x'))),
            'y' => max(0, min(100, $settings->int('bg_focus_y'))),
            'overlay' => max(0, min(100, $settings->int('bg_overlay'))),
            'onMobile' => $settings->bool('bg_mobile'),
        ];
    }

    /**
     * Value of <meta name="color-scheme"> (form controls and scrollbars follow it).
     */
    public function colorSchemeMeta(): string
    {
        return $this->scheme === 'auto' ? 'light dark' : $this->scheme;
    }

    /**
     * @return list<string> Fonts the page needs.
     */
    public function fonts(): array
    {
        return array_values(array_unique([$this->uiFont, $this->displayFont]));
    }

    /**
     * Inline CSS for the <head>: @font-face rules, the font variables and custom colours/radius.
     * The selectors are more specific than the stylesheet's, which loads after this block.
     */
    public function css(): string
    {
        $css = Fonts::faces($this->fonts())
            . ':root[data-portier-template]{--portier-font:' . Fonts::stack($this->uiFont) . ';--portier-font-display:' . Fonts::stack($this->displayFont) . '}';

        $shape = $this->radius >= 0 ? $this->radiusVariables($this->radius) : [];
        if ($shape !== []) {
            $css .= ':root[data-portier-template][data-portier-scheme]{' . self::declarations($shape) . '}';
        }

        $css .= $this->photoCss();

        $colors = $this->colorVariables();
        if ($colors !== []) {
            $block = ':root[data-portier-template][data-portier-scheme]{' . self::declarations($colors) . '}';
            // Templates with a dark variant: custom colours are made for the light look; the dark
            // look keeps its tested colours.
            if ($this->scheme === 'auto' && Templates::all()[$this->template]['dark'] === 'both') {
                $css .= '@media (prefers-color-scheme: light){' . $block . '}';
            } elseif (! ($this->scheme === 'dark' && Templates::all()[$this->template]['dark'] === 'both')) {
                $css .= $block;
            }
        }
        return $css;
    }

    /**
     * CSS variables of the background image. The template background becomes an overlay whose
     * opacity is 1 − transparency (Figma: 0 % = only template, 100 % = only image).
     */
    private function photoCss(): string
    {
        if ($this->photo === null) {
            return '';
        }
        $url = static fn (string $src): string => 'url("' . esc_url($src) . '")';
        $vars = [
            '--portier-photo' => $url($this->photo['url']),
            '--portier-photo-mobile' => $url($this->photo['mobile']),
            '--portier-photo-position' => $this->photo['x'] . '% ' . $this->photo['y'] . '%',
            '--portier-overlay' => (string) round(1 - $this->photo['overlay'] / 100, 2),
        ];
        $css = ':root[data-portier-photo]{' . self::declarations($vars) . '}';
        if ($this->photo['dark'] !== '') {
            $dark = self::declarations(['--portier-photo' => $url($this->photo['dark']), '--portier-photo-mobile' => $url($this->photo['dark'])]);
            $css .= ':root[data-portier-photo][data-portier-scheme="dark"]{' . $dark . '}'
                . '@media (prefers-color-scheme: dark){:root[data-portier-photo][data-portier-scheme="auto"]{' . $dark . '}}';
        }
        return $css;
    }

    /**
     * Whether any "Farben & Form" value differs from the template.
     */
    public function isCustomised(): bool
    {
        return array_filter($this->colors) !== [] || $this->radius >= 0 || $this->uiFont !== Templates::all()[$this->template]['ui'];
    }

    /**
     * @return array<string, string>
     */
    private function colorVariables(): array
    {
        $vars = [];
        if ($this->colors['primary'] !== '') {
            $vars['--portier-color-primary'] = $this->colors['primary'];
            $vars['--portier-color-primary-hover'] = Color::hover($this->colors['primary']);
            $vars['--portier-color-on-primary'] = Color::onColor($this->colors['primary']);
        }
        if ($this->colors['accent'] !== '') {
            $vars['--portier-color-accent'] = $this->colors['accent'];
            $vars['--portier-color-focus'] = $this->colors['accent'];
        }
        if ($this->colors['background'] !== '') {
            $vars['--portier-color-bg'] = $this->colors['background'];
        }
        return $vars;
    }

    /**
     * One radius drives the whole shape family (field, button, alert, card, sheet, checkbox).
     *
     * @return array<string, string>
     */
    private function radiusVariables(int $radius): array
    {
        return [
            '--portier-radius-field' => $radius . 'px',
            '--portier-radius-button' => $radius . 'px',
            '--portier-radius-alert' => $radius . 'px',
            '--portier-radius-check' => max(2, (int) round($radius / 2)) . 'px',
            '--portier-radius-card' => ($radius * 2) . 'px',
            '--portier-radius-sheet' => ($radius * 2 + 4) . 'px',
        ];
    }

    /**
     * @param array<string, string> $vars Validated values only (hex colours, pixel numbers).
     */
    private static function declarations(array $vars): string
    {
        $out = '';
        foreach ($vars as $name => $value) {
            $out .= $name . ':' . $value . ';';
        }
        return $out;
    }
}
