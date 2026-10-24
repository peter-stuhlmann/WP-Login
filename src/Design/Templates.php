<?php

declare(strict_types=1);

namespace Portier\Design;

/**
 * The eight login templates (Figma page "05 Templates").
 *
 * Colours, radii and layouts live in the stylesheet ([data-portier-template]); this registry
 * knows what PHP needs: fonts to load, whether a dark variant exists, whether the layout has a
 * side panel with text, and the default panel texts.
 */
final class Templates
{
    public const DEFAULT = 'aurora';

    /**
     * @return array<string, array{label: string, description: string, ui: string, display: string, dark: string, panel: bool}>
     *   dark: "both" (light + dark, follows the colour scheme setting), "light" or "dark" (fixed).
     */
    public static function all(): array
    {
        return [
            'aurora' => [
                'label' => 'Aurora',
                'description' => __('Glass card on an aurora gradient, light and dark.', 'portier'),
                'ui' => 'geist', 'display' => 'geist', 'dark' => 'both', 'panel' => false,
            ],
            'pure' => [
                'label' => 'Pure',
                'description' => __('Minimal, lots of white space, clear lines.', 'portier'),
                'ui' => 'geist', 'display' => 'geist', 'dark' => 'light', 'panel' => true,
            ],
            'studio' => [
                'label' => 'Studio',
                'description' => __('Split screen with a brand area.', 'portier'),
                'ui' => 'plus-jakarta-sans', 'display' => 'plus-jakarta-sans', 'dark' => 'light', 'panel' => true,
            ],
            'midnight' => [
                'label' => 'Midnight',
                'description' => __('Dark, grid, neon accent.', 'portier'),
                'ui' => 'geist', 'display' => 'space-grotesk', 'dark' => 'dark', 'panel' => false,
            ],
            'journal' => [
                'label' => 'Journal',
                'description' => __('Editorial, serif, warm paper.', 'portier'),
                'ui' => 'geist', 'display' => 'instrument-serif', 'dark' => 'light', 'panel' => true,
            ],
            'bold' => [
                'label' => 'Bold',
                'description' => __('Neo-brutalism with strong outlines.', 'portier'),
                'ui' => 'space-grotesk', 'display' => 'archivo-black', 'dark' => 'light', 'panel' => false,
            ],
            'cloud' => [
                'label' => 'Cloud',
                'description' => __('Soft, pastel, large radii.', 'portier'),
                'ui' => 'manrope', 'display' => 'bricolage-grotesque', 'dark' => 'light', 'panel' => false,
            ],
            'enterprise' => [
                'label' => 'Enterprise',
                'description' => __('Matter-of-fact and structured, with a header bar.', 'portier'),
                'ui' => 'ibm-plex-sans', 'display' => 'ibm-plex-sans', 'dark' => 'light', 'panel' => false,
            ],
        ];
    }

    /**
     * Valid template id (falls back to the default).
     */
    public static function id(string $id): string
    {
        return in_array($id, self::ids(), true) ? $id : self::DEFAULT;
    }

    /**
     * Template ids without translations (usable before `init`, e.g. in settings sanitising).
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return ['aurora', 'pure', 'studio', 'midnight', 'journal', 'bold', 'cloud', 'enterprise'];
    }

    /**
     * Default texts of the side panel / header label. Real content only: the site's own name and
     * tagline – no invented quotes or status messages.
     *
     * @return array{headline: string, text: string}
     */
    public static function defaultTexts(string $id, string $siteName, string $tagline): array
    {
        return match ($id) {
            'pure' => [
                'headline' => __('Simple. Secure. In.', 'portier'),
                'text' => __('No clutter, no noise – just what you need to log in.', 'portier'),
            ],
            'studio' => [
                /* translators: %s: site name */
                'headline' => $tagline !== '' ? $tagline : sprintf(__('Welcome to %s', 'portier'), $siteName),
                'text' => '',
            ],
            'journal' => [
                'headline' => __('Welcome back.', 'portier'),
                /* translators: %s: site name */
                'text' => sprintf(__('Log in to continue with %s.', 'portier'), $siteName),
            ],
            // Enterprise shows the tagline next to the name in the header bar.
            'enterprise' => ['headline' => $tagline, 'text' => ''],
            default => ['headline' => '', 'text' => ''],
        };
    }
}
