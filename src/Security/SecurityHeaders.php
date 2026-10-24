<?php

declare(strict_types=1);

namespace Portier\Security;

/**
 * HTTP headers for the login screen.
 *
 * The Content-Security-Policy is intentionally compatible with plugins that add scripts to the
 * login page (captcha, SSO): it does not restrict scripts, but prevents clickjacking
 * (frame-ancestors), form hijacking (form-action) and base-tag injection. Sites can tighten it
 * with the `portier/content_security_policy` filter.
 */
final class SecurityHeaders
{
    /**
     * @param bool $framable True only for the admin's live preview: same-origin framing allowed.
     * @return array<string, string>
     */
    public static function forLoginPage(bool $framable = false): array
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter: same hosts wp_safe_redirect() allows.
        $hosts = (array) apply_filters('allowed_redirect_hosts', [(string) wp_parse_url(home_url(), PHP_URL_HOST)]);
        $formTargets = array_map(static fn ($host): string => 'https://' . $host . ' http://' . $host, array_filter($hosts, 'is_string'));

        $policy = sprintf(
            "frame-ancestors %s; base-uri 'self'; object-src 'none'; form-action 'self' %s",
            $framable ? "'self'" : "'none'",
            implode(' ', $formTargets)
        );
        /**
         * Filters the Content-Security-Policy of the Portier login screen.
         *
         * @param string $policy CSP header value.
         */
        $policy = (string) apply_filters('portier/content_security_policy', $policy);

        return [
            'Content-Security-Policy' => $policy,
            'X-Frame-Options' => $framable ? 'SAMEORIGIN' : 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
