<?php

declare(strict_types=1);

namespace Portier\Audit;

use Portier\Frontend\Device;
use Portier\Security\ClientIp;
use WP_User;

/**
 * Writes audit log lines: when, who, from where, what.
 *
 *     2026-10-23 14:05:31 +02:00 | Lena Berger <lena@example.org> #5 Administrator | 203.0.113.9 · Chrome on Windows | Admin | settings.changed | Passkeys enabled
 *
 * Fields: time with seconds and offset (site time zone) · person (name, e-mail, user ID, role;
 * "–" when nobody is logged in) · IP address and browser · where it happened (admin, profile,
 * login page, WP-CLI …) · event code (stable, for filtering) · what, in the site language.
 * On multisite the site follows the time. Control characters are removed from every field, so
 * no value can start a forged line.
 */
final class AuditLog
{
    /** Set while Portier's login page handles the request (see Controller::handle()). */
    public static bool $onLoginPage = false;

    private static ?self $instance = null;

    public function __construct(
        private readonly LogFile $file,
        private readonly string $proxyHeader = '',
    ) {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            $settings = get_option('portier_settings');
            $header = is_array($settings) && is_string($settings['trusted_proxy_header'] ?? null) ? $settings['trusted_proxy_header'] : '';
            self::$instance = new self(LogFile::fromConfig(), $header);
        }
        return self::$instance;
    }

    /**
     * For tests: another file or proxy setting.
     */
    public static function use(?self $log): void
    {
        self::$instance = $log;
    }

    /**
     * Whether the log is switched on (setting "audit_log", on by default).
     */
    public static function enabled(): bool
    {
        $settings = get_option('portier_settings');
        return ! is_array($settings) || ! array_key_exists('audit_log', $settings) || (bool) $settings['audit_log'];
    }

    public function file(): LogFile
    {
        return $this->file;
    }

    /**
     * Records one event with one or more messages (one line each).
     *
     * @param string       $event    Stable code, e.g. "settings.changed".
     * @param list<string> $messages What happened, already in the site language.
     * @param WP_User|null $actor    Who did it (default: the current user).
     * @param WP_User|null $subject  Whose account it was about, if somebody else's.
     * @param bool         $force    Write even when the log is switched off (its own switching off).
     */
    public function record(string $event, array $messages, ?WP_User $actor = null, ?WP_User $subject = null, bool $force = false): void
    {
        if ($messages === [] || (! $force && ! self::enabled())) {
            return;
        }
        $now = time();
        $actor ??= is_user_logged_in() ? wp_get_current_user() : null;
        $prefix = [
            (string) wp_date('Y-m-d H:i:s P', $now),
            $this->person($actor),
            $this->origin(),
            $this->source(),
        ];
        if (is_multisite()) {
            array_splice($prefix, 1, 0, [sprintf('%s (#%d)', (string) get_bloginfo('name'), get_current_blog_id())]);
        }
        $suffix = $subject !== null && ($actor === null || $subject->ID !== $actor->ID)
            /* translators: %s: person whose account was changed */
            ? ' – ' . sprintf(__('for %s', 'portier'), $this->person($subject, false))
            : '';

        $lines = [];
        foreach ($messages as $message) {
            $lines[] = implode(' | ', array_map([self::class, 'clean'], array_merge($prefix, [$event, $message . $suffix])));
        }
        $this->file->append($lines, $now);
    }

    /**
     * "Name <e-mail> #ID Role" – or "–" for nobody.
     */
    public function person(?WP_User $user, bool $withRole = true): string
    {
        if ($user === null || $user->ID === 0) {
            return '–';
        }
        $role = '';
        if ($withRole && $user->roles !== []) {
            $roles = wp_roles()->get_names();
            $first = (string) reset($user->roles);
            $role = ' ' . translate_user_role($roles[$first] ?? $first);
        }
        return sprintf('%s <%s> #%d%s', $user->display_name, $user->user_email, $user->ID, $role);
    }

    /**
     * IP address (from the trusted proxy header if configured) and browser.
     */
    private function origin(): string
    {
        // Command line (WP-CLI, scripts, cron runners): no visitor, no IP address.
        if (PHP_SAPI === 'cli' && ! defined('PORTIER_AUDIT_TESTING')) {
            return '–';
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ClientIp validates; the user agent is only described.
        $server = $_SERVER;
        $ip = ClientIp::resolve($server, $this->proxyHeader);
        $device = Device::describe(sanitize_text_field(wp_unslash((string) ($server['HTTP_USER_AGENT'] ?? ''))));
        $browser = $device['browser'] !== '' && $device['os'] !== ''
            /* translators: 1: browser, 2: operating system, e.g. "Chrome on Windows" */
            ? sprintf(__('%1$s on %2$s', 'portier'), $device['browser'], $device['os'])
            : $device['browser'] . $device['os'];
        return ($ip !== '' && $ip !== '0.0.0.0' ? $ip : '–') . ($browser !== '' ? ' · ' . $browser : '');
    }

    /**
     * Where the action happened.
     */
    private function source(): string
    {
        if (defined('WP_CLI') && WP_CLI) {
            return 'WP-CLI';
        }
        if (PHP_SAPI === 'cli' && ! defined('PORTIER_AUDIT_TESTING')) {
            return 'CLI';
        }
        if (wp_doing_cron()) {
            return 'Cron';
        }
        if (self::$onLoginPage) {
            return __('Login page', 'portier');
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only compared with fixed paths.
        $uri = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (defined('REST_REQUEST') && REST_REQUEST) {
            if (str_contains($uri, '/portier/v1/passkeys')) {
                return __('Profile', 'portier');
            }
            return str_contains($uri, '/portier/v1/') ? 'Admin' : 'REST API';
        }
        if (is_admin()) {
            global $pagenow;
            return in_array($pagenow, ['profile.php', 'user-edit.php'], true) ? __('Profile', 'portier') : 'Admin (' . $pagenow . ')';
        }
        return __('Website', 'portier');
    }

    /**
     * One line per entry: control characters (line breaks, tabs, escape codes) become spaces.
     */
    public static function clean(string $field): string
    {
        $field = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $field));
        return mb_substr($field, 0, 2000);
    }
}
