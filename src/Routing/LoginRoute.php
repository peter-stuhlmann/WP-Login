<?php

declare(strict_types=1);

namespace Portier\Routing;

use WP;

/**
 * The public login address, e.g. https://example.com/login/.
 *
 * Works with pretty permalinks (rewrite rule) and with plain permalinks (?portier=1).
 * An optional two-letter language prefix (Polylang/WPML style, e.g. /en/login/) is accepted
 * so multilingual sites do not end up on a 404.
 */
final class LoginRoute
{
    public const QUERY_VAR = 'portier';
    /** Provider id of a social login callback (/login/oauth/<provider>/). */
    public const PROVIDER_VAR = 'portier_provider';
    public const SIGNATURE_OPTION = 'portier_rewrite_signature';

    public function __construct(
        private readonly string $slug,
        private readonly bool $enabled,
    ) {
    }

    public function register(): void
    {
        if ($this->enabled) {
            add_action('init', [$this, 'addRewriteRule']);
            add_filter('query_vars', [$this, 'addQueryVar']);
        }
        add_action('init', [$this, 'maybeFlush'], 99);
    }

    public function addRewriteRule(): void
    {
        add_rewrite_rule('(?:[a-z]{2,3}(?:-[a-z]{2,4})?/)?' . $this->slug . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        // Social login callbacks get a fixed path – providers compare redirect URIs exactly.
        add_rewrite_rule($this->slug . '/oauth/([a-z0-9-]{2,32})/?$', 'index.php?' . self::QUERY_VAR . '=1&' . self::PROVIDER_VAR . '=$matches[1]', 'top');
    }

    /**
     * @param list<string> $vars
     * @return list<string>
     */
    public function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::PROVIDER_VAR;
        return $vars;
    }

    /**
     * Flushes rewrite rules once whenever the route configuration changed (activation,
     * slug change, toggle). Cheap: a single option read per request.
     */
    public function maybeFlush(): void
    {
        $signature = md5($this->slug . '|' . (int) $this->enabled . '|' . PORTIER_VERSION);
        if (get_option(self::SIGNATURE_OPTION) !== $signature) {
            flush_rewrite_rules(false);
            update_option(self::SIGNATURE_OPTION, $signature, true);
        }
    }

    public function matches(WP $wp): bool
    {
        return $this->enabled && ! empty($wp->query_vars[self::QUERY_VAR]);
    }

    /**
     * Public URL of a login screen.
     *
     * @param array<string, scalar> $args Additional query arguments.
     */
    public function url(string $action = 'login', array $args = [], ?string $scheme = 'login'): string
    {
        if ($this->usesPrettyPermalinks()) {
            $url = home_url(user_trailingslashit($this->slug), $scheme);
        } else {
            $url = add_query_arg(self::QUERY_VAR, '1', home_url('/', $scheme));
        }
        if ($action !== 'login') {
            $args = ['action' => $action] + $args;
        }
        return $args === [] ? $url : add_query_arg(array_map('rawurlencode', array_map('strval', $args)), $url);
    }

    /**
     * Redirect URI of a social login provider, e.g. https://example.com/login/oauth/google/.
     * Registered by the site owner at the provider, so it must never change by itself.
     */
    public function callbackUrl(string $provider): string
    {
        if ($this->usesPrettyPermalinks()) {
            return home_url(user_trailingslashit($this->slug . '/oauth/' . $provider), 'login');
        }
        return add_query_arg([self::QUERY_VAR => '1', self::PROVIDER_VAR => $provider], home_url('/', 'login'));
    }

    /**
     * Whether a URL points to the Portier screen (used to load follow-up screens in place).
     */
    public function owns(string $url): bool
    {
        $target = wp_parse_url($url);
        $own = wp_parse_url($this->url());
        if (! is_array($target) || ! is_array($own) || ($target['host'] ?? '') !== ($own['host'] ?? '')) {
            return false;
        }
        if (untrailingslashit($target['path'] ?? '/') !== untrailingslashit($own['path'] ?? '/')) {
            return false;
        }
        if ($this->usesPrettyPermalinks()) {
            return true;
        }
        $query = [];
        parse_str($target['query'] ?? '', $query);
        return ! empty($query[self::QUERY_VAR]);
    }

    /**
     * Cookie path that covers only the login page.
     */
    public function cookiePath(): string
    {
        $path = (string) wp_parse_url($this->url(), PHP_URL_PATH);
        return $path === '' ? '/' : $path;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    private function usesPrettyPermalinks(): bool
    {
        return (string) get_option('permalink_structure') !== '';
    }
}
