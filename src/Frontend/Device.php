<?php

declare(strict_types=1);

namespace Portier\Frontend;

/**
 * Human-readable "Browser on OS" from a user agent, for the magic link confirmation page.
 * Deliberately simple: it helps people notice a login they did not start; it is no security
 * feature (user agents can be faked).
 */
final class Device
{
    private const BROWSERS = ['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'];
    private const SYSTEMS = [
        'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Android' => 'Android', 'Windows' => 'Windows',
        'Mac OS X' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux',
    ];

    /** Systems shown with a phone/tablet icon instead of a monitor. */
    private const MOBILE = ['iOS', 'iPadOS', 'Android'];

    /**
     * @return array{browser: string, os: string, mobile: bool}
     */
    public static function describe(string $userAgent): array
    {
        $os = self::first(self::SYSTEMS, $userAgent);
        return [
            'browser' => self::first(self::BROWSERS, $userAgent),
            'os' => $os,
            'mobile' => in_array($os, self::MOBILE, true),
        ];
    }

    /**
     * @param array<string, string> $map
     */
    private static function first(array $map, string $userAgent): string
    {
        foreach ($map as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }
        return '';
    }
}
