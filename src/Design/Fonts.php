<?php

declare(strict_types=1);

namespace Portier\Design;

/**
 * Self-hosted fonts (assets/fonts, written by bin/copy-fonts.mjs). No font CDN is ever used.
 */
final class Fonts
{
    /** @var array<string, array{family: string, license: string, faces: list<array{file: string, weight: string, style: string, range: string}>}>|null */
    private static ?array $manifest = null;

    /**
     * Font choices for the admin ("Schrift"): id => family name.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::manifest() as $id => $font) {
            $choices[$id] = $font['family'];
        }
        return $choices;
    }

    public static function exists(string $id): bool
    {
        return isset(self::manifest()[$id]);
    }

    /**
     * CSS font stack for a font id, with system fallbacks.
     */
    public static function stack(string $id): string
    {
        $family = self::manifest()[$id]['family'] ?? 'Geist';
        $fallback = $id === 'instrument-serif' ? 'Georgia, "Times New Roman", serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
        return '"' . $family . '", ' . $fallback;
    }

    /**
     * @font-face rules for the given fonts.
     *
     * @param list<string> $ids
     */
    public static function faces(array $ids): string
    {
        $css = '';
        foreach (array_unique($ids) as $id) {
            $font = self::manifest()[$id] ?? null;
            if ($font === null) {
                continue;
            }
            foreach ($font['faces'] as $face) {
                $css .= sprintf(
                    "@font-face{font-family:'%s';src:url('%s') format('woff2');font-weight:%s;font-style:%s;font-display:swap%s}",
                    $font['family'],
                    esc_url(self::url($face['file'])),
                    preg_replace('/[^0-9 ]/', '', $face['weight']),
                    $face['style'] === 'italic' ? 'italic' : 'normal',
                    $face['range'] !== '' ? ';unicode-range:' . preg_replace('/[^A-Fa-f0-9U+,\-]/', '', $face['range']) : ''
                );
            }
        }
        return $css;
    }

    /**
     * URL of the (latin, normal) file to preload for a font – the one every page needs.
     */
    public static function preloadUrl(string $id): string
    {
        $faces = self::manifest()[$id]['faces'] ?? [];
        foreach ($faces as $face) {
            if ($face['style'] === 'normal' && ! str_contains($face['file'], 'latin-ext')) {
                return self::url($face['file']);
            }
        }
        return '';
    }

    private static function url(string $file): string
    {
        return plugins_url('assets/fonts/' . $file, PORTIER_FILE);
    }

    /**
     * @return array<string, array{family: string, license: string, faces: list<array{file: string, weight: string, style: string, range: string}>}>
     */
    private static function manifest(): array
    {
        if (self::$manifest === null) {
            $json = is_readable(PORTIER_DIR . '/assets/fonts/fonts.json') ? (string) file_get_contents(PORTIER_DIR . '/assets/fonts/fonts.json') : '';
            $data = json_decode($json, true);
            self::$manifest = is_array($data) ? $data : [];
        }
        return self::$manifest;
    }
}
