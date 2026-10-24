<?php

declare(strict_types=1);

namespace Portier\Design;

/**
 * Small colour helper for custom colours: validation, mixing and WCAG contrast.
 */
final class Color
{
    /**
     * Normalised "#rrggbb", or '' when the value is not a hex colour.
     */
    public static function hex(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $short)) {
            return '#' . $short[1] . $short[1] . $short[2] . $short[2] . $short[3] . $short[3];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : '';
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        $hex = self::hex($hex);
        if ($hex === '') {
            return [0, 0, 0];
        }
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /**
     * Relative luminance (WCAG 2.x).
     */
    public static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $channel): float {
            $c = $channel / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Contrast ratio between two colours (1–21).
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Mixes $hex with $with by $amount (0–1).
     */
    public static function mix(string $hex, string $with, float $amount): string
    {
        $a = self::rgb($hex);
        $b = self::rgb($with);
        $mixed = [];
        foreach ([0, 1, 2] as $i) {
            $mixed[] = (int) round($a[$i] + ($b[$i] - $a[$i]) * $amount);
        }
        return vsprintf('#%02x%02x%02x', $mixed);
    }

    /**
     * Text colour for a background: white or near-black, whichever contrasts more.
     */
    public static function onColor(string $background): string
    {
        return self::contrast('#ffffff', $background) >= self::contrast('#0f0e17', $background) ? '#ffffff' : '#0f0e17';
    }

    /**
     * Hover shade: a bit darker on light colours, a bit lighter on dark ones.
     */
    public static function hover(string $hex): string
    {
        return self::luminance($hex) > 0.4 ? self::mix($hex, '#000000', 0.12) : self::mix($hex, '#ffffff', 0.14);
    }
}
