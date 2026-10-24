<?php

declare(strict_types=1);

namespace Portier\Routing;

/**
 * Decides what happens when someone opens wp-login.php directly.
 *
 * - Pass-through actions always work (logout, password protected posts, …).
 * - Login POST requests keep working in "redirect" mode, because wp_login_form() widgets of
 *   themes and plugins post to core. They are rate limited like every other login. Other forms
 *   posted to core (lost password, registration, reset) are redirected to Portier's screens.
 * - Everything else is redirected to the Portier screen – or answered with a 404 in
 *   "block" mode.
 */
final class CoreLoginGuard
{
    public const ALLOW = 'allow';
    public const REDIRECT = 'redirect';
    public const BLOCK = 'block';

    public function __construct(
        private readonly UrlRewriter $rewriter,
        private readonly string $behavior,
    ) {
    }

    public function register(): void
    {
        add_action('login_init', [$this, 'guard'], 0);
    }

    /**
     * Pure decision logic (unit tested).
     *
     * @param array<string, mixed> $query
     */
    public static function decide(string $method, array $query, string $behavior): string
    {
        if (CoreLogin::portierAction($query) === null) {
            return self::ALLOW;
        }
        if ($behavior === 'block') {
            return self::BLOCK;
        }
        // Only the login itself: lost password, registration and reset posted to core would skip
        // Portier's e-mail limits, bot traps and minimum password length.
        return strtoupper($method) === 'POST' && CoreLogin::portierAction($query) === 'login' ? self::ALLOW : self::REDIRECT;
    }

    public function guard(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing decision.
        $query = wp_unslash($_GET);

        switch (self::decide($method, $query, $this->behavior)) {
            case self::REDIRECT:
                $target = $this->rewriter->map(CoreLogin::url(array_filter($query, 'is_scalar')));
                if ($target !== null) {
                    wp_safe_redirect($target);
                    exit;
                }
                return;
            case self::BLOCK:
                $this->notFound();
                // no break – notFound() exits.
            default:
                return;
        }
    }

    /**
     * Renders the theme's 404 page (classic and block themes).
     */
    private function notFound(): never
    {
        global $wp_query;
        status_header(404);
        nocache_headers();
        if ($wp_query instanceof \WP_Query) {
            $wp_query->set_404();
        }
        $template = get_404_template();
        if ($template !== '') {
            include $template;
        } else {
            echo esc_html__('Page not found.', 'portier');
        }
        exit;
    }
}
