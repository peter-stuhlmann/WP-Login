<?php

declare(strict_types=1);

namespace Portier\Routing;

/**
 * Points every core link to wp-login.php at the Portier screen.
 *
 * Core builds all of its login links through site_url()/network_site_url() – including
 * wp_login_url(), wp_lostpassword_url(), wp_registration_url() and the password reset e-mail –
 * so filtering those two functions catches them all without duplicating core logic.
 *
 * Deliberately left untouched:
 * - pass-through actions (logout, postpass, …) {@see CoreLogin::PASSTHROUGH_ACTIONS},
 * - the `login_post` scheme used by wp_login_form(): third-party login widgets keep posting to
 *   core, which stays protected by {@see \Portier\Security\LoginThrottle}.
 */
final class UrlRewriter
{
    public function __construct(private readonly LoginRoute $route)
    {
    }

    public function register(): void
    {
        add_filter('site_url', [$this, 'filterSiteUrl'], 20, 3);
        add_filter('network_site_url', [$this, 'filterSiteUrl'], 20, 3);
        add_filter('wp_redirect', [$this, 'filterRedirect'], 20);
    }

    public function filterSiteUrl(string $url, string $path = '', ?string $scheme = null): string
    {
        if ($scheme === 'login_post' || ! str_starts_with(ltrim($path, '/'), 'wp-login.php')) {
            return $url;
        }
        return $this->map($url) ?? $url;
    }

    /**
     * Core sometimes redirects to the relative path "wp-login.php?…" (e.g. after logout).
     */
    public function filterRedirect(string $location): string
    {
        if (! preg_match('#(^|/)wp-login\.php(\?|$)#', $location)) {
            return $location;
        }
        $host = wp_parse_url($location, PHP_URL_HOST);
        if ($host !== null && $host !== wp_parse_url(home_url(), PHP_URL_HOST)) {
            return $location;
        }
        return $this->map($location) ?? $location;
    }

    /**
     * Maps a wp-login.php URL to the Portier URL, keeping all query arguments.
     * Returns null when core has to handle the action.
     */
    public function map(string $url): ?string
    {
        $query = [];
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        /** @var array<string, mixed> $query */
        $action = CoreLogin::portierAction($query);
        if ($action === null) {
            return null;
        }
        unset($query['action']);
        /** @var array<string, scalar> $query */
        $query = array_filter($query, 'is_scalar');
        return $this->route->url($action, $query);
    }
}
