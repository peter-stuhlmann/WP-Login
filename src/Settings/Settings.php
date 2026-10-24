<?php

declare(strict_types=1);

namespace Portier\Settings;

/**
 * Typed access to the plugin settings stored in one option.
 *
 * Every value passes through {@see Settings::sanitize()}, so the rest of the plugin can rely on
 * well-formed values. Registration itself is not stored here: it maps onto the core options
 * `users_can_register` / `default_role` (single site) so WordPress and Portier never disagree.
 */
final class Settings
{
    public const OPTION = 'portier_settings';

    /** Slugs that would collide with WordPress itself. */
    private const RESERVED_SLUGS = [
        'wp-admin', 'wp-login', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json', 'admin', 'dashboard',
        'feed', 'rss', 'rss2', 'atom', 'rdf', 'comments', 'page', 'embed', 'trackback', 'xmlrpc', 'xmlrpc.php',
        'sitemap', 'wp-sitemap', 'robots.txt', 'favicon.ico', 'index.php', 'search', 'author', 'category', 'tag',
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    private bool $preview = false;

    /**
     * Default values. The custom login is active right after activation; the kill switch
     * (PORTIER_DISABLE) and the master toggle always lead back to the core login.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'slug' => 'login',
            'core_login_behavior' => 'redirect', // redirect | block
            // "one": e-mail and password on one screen (default); "two": e-mail first, then the
            // password on its own screen (Figma).
            'login_steps' => 'one',
            'min_password_length' => 12,
            'require_privacy_consent' => true,
            'language_switcher' => true,
            'imprint_page' => 0,
            'accessibility_page' => 0,
            'rate_limit_attempts' => 5,
            'rate_limit_lockout_minutes' => 5,
            'rate_limit_account_attempts' => 20,
            'trusted_proxy_header' => '', // '', HTTP_X_FORWARDED_FOR, HTTP_CF_CONNECTING_IP, HTTP_X_REAL_IP
            'disable_xmlrpc' => false,
            // Two-factor authentication: off | optional (users opt in) | required (for the roles below).
            'two_factor_mode' => 'optional',
            'two_factor_roles' => ['administrator'],
            'trust_device_days' => 30, // 0 disables "trust this device".
            'magic_link' => false,
            'magic_link_minutes' => 10,
            // Passkeys (WebAuthn): log in with the device's screen lock, without password and 2FA.
            'passkeys' => true,
            // Order of the methods below the login form (passkey button and social providers);
            // ids not listed follow in their default order.
            'method_order' => [],
            // Audit log file (wp-content/portier-logs or PORTIER_LOG_DIR); see src/Audit.
            'audit_log' => true,
            // Social login: per provider id, see providerValues(). Secrets are stored encrypted.
            'providers' => [],
            // Roles that may not log in with a social account (a hijacked Google account must not
            // open the admin). Administrators by default.
            'social_blocked_roles' => ['administrator'],
            // Design (Figma "Admin · Design"): one of Templates::ids(), colour scheme for templates
            // with a dark variant, and optional texts for templates with a side panel.
            'template' => 'aurora',
            'color_scheme' => 'auto', // auto | light | dark
            'design_headline' => '',
            'design_text' => '',
            // "Farben & Form": empty / -1 = the template's value.
            'custom_primary' => '',
            'custom_accent' => '',
            'custom_background' => '',
            'custom_radius' => -1,
            'custom_font' => '',
            // Custom CSS (only with unfiltered_html, sanitised; older versions in portier_css_revisions).
            'custom_css' => '',
            // Background image behind the template background (Figma "Hintergrundbild"): the template
            // background becomes an overlay; bg_overlay = its transparency in % (0 = only template,
            // 100 = only image). Focus point in %, optional dark-mode image, image on mobile.
            'bg_image' => 0,
            'bg_image_dark' => 0,
            'bg_dark' => false,
            'bg_focus_x' => 50,
            'bg_focus_y' => 50,
            'bg_overlay' => 50,
            'bg_mobile' => true,
        ];
    }

    /**
     * Normalised settings of one social login provider.
     *
     * @param array<string, mixed> $input
     * @return array{enabled: bool, client_id: string, client_secret: string, link: string, signup: bool}
     */
    public static function providerValues(array $input): array
    {
        $link = (string) ($input['link'] ?? 'verified');
        $clientId = (string) preg_replace('/[^\x21-\x7e]/', '', (string) ($input['client_id'] ?? ''));
        return [
            'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'client_id' => substr($clientId, 0, 255),
            // Already encrypted by the caller (see RestController); kept as an opaque string.
            'client_secret' => substr(trim((string) ($input['client_secret'] ?? '')), 0, 2000),
            'link' => in_array($link, ['verified', 'never'], true) ? $link : 'verified',
            'signup' => filter_var($input['signup'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = $this->sanitize(is_array($stored) ? $stored : [])['values'];
        }
        return $this->cache;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? self::defaults()[$key] ?? null;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        return array_values(array_filter((array) $this->get($key), 'is_string'));
    }

    /**
     * Merges, sanitizes and stores new values.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, mixed>, errors: array<string, array{code: string, min?: int, max?: int}>}
     */
    public function update(array $input): array
    {
        $result = $this->sanitize(array_merge($this->all(), $input));
        if ($result['errors'] === []) {
            update_option(self::OPTION, $result['values']);
            $this->cache = $result['values'];
        }
        return $result;
    }

    public function flushCache(): void
    {
        $this->cache = null;
    }

    /**
     * Settings that protect accounts. On multisite accounts belong to the whole network – also
     * the super admins' – so only network administrators may change these on any site; a site
     * administrator could otherwise switch off 2FA where super admins log in.
     */
    public const NETWORK_KEYS = [
        'enabled', 'core_login_behavior', 'min_password_length',
        'rate_limit_attempts', 'rate_limit_lockout_minutes', 'rate_limit_account_attempts', 'trusted_proxy_header',
        'disable_xmlrpc', 'two_factor_mode', 'two_factor_roles', 'trust_device_days',
        'magic_link', 'magic_link_minutes', 'social_blocked_roles', 'audit_log',
    ];

    /** Settings the admin's live preview may change (only for one request, never saved). */
    public const PREVIEW_KEYS = [
        'template', 'color_scheme', 'design_headline', 'design_text', 'login_steps',
        'custom_primary', 'custom_accent', 'custom_background', 'custom_radius', 'custom_font',
        'bg_image', 'bg_image_dark', 'bg_dark', 'bg_focus_x', 'bg_focus_y', 'bg_overlay', 'bg_mobile',
    ];

    /**
     * Applies unsaved design values for the current request only (live preview in the admin).
     * Only {@see Settings::PREVIEW_KEYS} are taken; everything is sanitised as usual.
     *
     * @param array<string, mixed> $input
     */
    public function preview(array $input): void
    {
        $allowed = array_intersect_key($input, array_flip(self::PREVIEW_KEYS));
        $this->cache = $this->sanitize(array_merge($this->all(), $allowed))['values'];
        $this->preview = true;
    }

    /**
     * Whether this request renders the admin's live preview.
     */
    public function isPreview(): bool
    {
        return $this->preview;
    }

    /**
     * Normalises raw input against the schema. Unknown keys are dropped, invalid values fall
     * back to their default and are reported in `errors` (keyed by setting name) as error codes.
     * Codes are translated by {@see Settings::errorMessage()} only when shown, because this
     * method also runs before `init`, where translations must not be loaded yet.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, mixed>, errors: array<string, array{code: string, min?: int, max?: int}>}
     */
    public function sanitize(array $input): array
    {
        $defaults = self::defaults();
        $values = $defaults;
        $errors = [];

        foreach (['enabled', 'require_privacy_consent', 'language_switcher', 'disable_xmlrpc', 'magic_link', 'passkeys', 'audit_log', 'bg_dark', 'bg_mobile'] as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('slug', $input)) {
            $slug = self::normaliseSlug((string) $input['slug']);
            $error = self::validateSlug($slug);
            if ($error === null) {
                $values['slug'] = $slug;
            } else {
                $errors['slug'] = ['code' => $error];
            }
        }

        if (isset($input['core_login_behavior'])) {
            $behavior = (string) $input['core_login_behavior'];
            $values['core_login_behavior'] = in_array($behavior, ['redirect', 'block'], true) ? $behavior : $defaults['core_login_behavior'];
        }
        if (isset($input['template'])) {
            $values['template'] = \Portier\Design\Templates::id((string) $input['template']);
        }
        if (isset($input['color_scheme'])) {
            $scheme = (string) $input['color_scheme'];
            $values['color_scheme'] = in_array($scheme, ['auto', 'light', 'dark'], true) ? $scheme : $defaults['color_scheme'];
        }
        foreach (['design_headline' => 120, 'design_text' => 300] as $key => $max) {
            if (array_key_exists($key, $input)) {
                // Plain text only (shown escaped); line breaks are not needed.
                $values[$key] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $input[$key]))), 0, $max);
            }
        }
        foreach (['custom_primary', 'custom_accent', 'custom_background'] as $key) {
            if (array_key_exists($key, $input)) {
                $color = \Portier\Design\Color::hex((string) $input[$key]);
                if ($color === '' && trim((string) $input[$key]) !== '') {
                    $errors[$key] = ['code' => 'color'];
                }
                $values[$key] = $color;
            }
        }
        if (array_key_exists('custom_radius', $input)) {
            $radius = filter_var($input['custom_radius'], FILTER_VALIDATE_INT);
            if ($radius === false || $radius < -1 || $radius > 32) {
                $errors['custom_radius'] = ['code' => 'out_of_range', 'min' => 0, 'max' => 32];
            } else {
                $values['custom_radius'] = $radius;
            }
        }
        if (array_key_exists('custom_font', $input)) {
            $font = (string) $input['custom_font'];
            $values['custom_font'] = $font !== '' && \Portier\Design\Fonts::exists($font) ? $font : '';
        }
        if (array_key_exists('custom_css', $input)) {
            $values['custom_css'] = \Portier\Design\CssSanitizer::clean((string) $input['custom_css']);
        }

        if (isset($input['login_steps'])) {
            $steps = (string) $input['login_steps'];
            $values['login_steps'] = in_array($steps, ['one', 'two'], true) ? $steps : $defaults['login_steps'];
        }

        $ranges = [
            'min_password_length' => [8, 64],
            'rate_limit_attempts' => [3, 50],
            'rate_limit_lockout_minutes' => [1, 1440],
            'rate_limit_account_attempts' => [5, 500],
            'trust_device_days' => [0, 90],
            'magic_link_minutes' => [5, 60],
            'bg_focus_x' => [0, 100],
            'bg_focus_y' => [0, 100],
            'bg_overlay' => [0, 100],
        ];
        foreach ($ranges as $key => [$min, $max]) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $number = filter_var($input[$key], FILTER_VALIDATE_INT);
            if ($number === false || $number < $min || $number > $max) {
                $errors[$key] = ['code' => 'out_of_range', 'min' => $min, 'max' => $max];
                continue;
            }
            $values[$key] = $number;
        }

        foreach (['imprint_page', 'accessibility_page', 'bg_image', 'bg_image_dark'] as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = max(0, (int) $input[$key]);
            }
        }

        if (isset($input['two_factor_mode'])) {
            $mode = (string) $input['two_factor_mode'];
            $values['two_factor_mode'] = in_array($mode, ['off', 'optional', 'required'], true) ? $mode : $defaults['two_factor_mode'];
        }
        // Only the format of roles is checked here (this also runs before roles are loaded);
        // the REST endpoint verifies that they exist.
        foreach (['two_factor_roles', 'social_blocked_roles'] as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = self::roleList($input[$key]);
            }
        }

        if (array_key_exists('providers', $input)) {
            $values['providers'] = [];
            foreach (is_array($input['providers']) ? $input['providers'] : [] as $id => $provider) {
                if (is_string($id) && preg_match('/^[a-z0-9-]{2,32}$/', $id) && is_array($provider)) {
                    $values['providers'][$id] = self::providerValues($provider);
                }
            }
        }

        if (array_key_exists('method_order', $input)) {
            // Format only (ids like provider ids); unknown ids are ignored when rendering.
            $order = [];
            foreach (is_array($input['method_order']) ? $input['method_order'] : [] as $id) {
                if (is_string($id) && preg_match('/^[a-z0-9-]{2,32}$/', $id) && ! in_array($id, $order, true) && count($order) < 50) {
                    $order[] = $id;
                }
            }
            $values['method_order'] = $order;
        }

        if (isset($input['trusted_proxy_header'])) {
            $header = (string) $input['trusted_proxy_header'];
            $values['trusted_proxy_header'] = in_array($header, ['', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'], true) ? $header : '';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @return list<string>
     */
    private static function roleList(mixed $roles): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($role): string => (string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $role)),
            is_array($roles) ? $roles : []
        ))));
    }

    public static function normaliseSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = trim($slug, '/');
        return (string) preg_replace('/[^a-z0-9-]+/', '-', $slug);
    }

    /**
     * @return string|null Error code (`slug_format`, `slug_reserved`) or null when the slug is usable.
     */
    public static function validateSlug(string $slug): ?string
    {
        if (strlen($slug) < 2 || strlen($slug) > 50 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return 'slug_format';
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return 'slug_reserved';
        }
        return null;
    }

    /**
     * Human readable, translated message for an error returned by {@see Settings::sanitize()}.
     *
     * @param array{code: string, min?: int, max?: int} $error
     */
    public static function errorMessage(array $error): string
    {
        return match ($error['code']) {
            'slug_format' => __('Use 2–50 lowercase letters, digits or hyphens (no hyphen at the start or end).', 'portier'),
            'slug_reserved' => __('This address is reserved by WordPress. Please choose another one.', 'portier'),
            'color' => __('Please enter a colour as a hex code, e.g. #6230e0.', 'portier'),
            'out_of_range' => sprintf(
                /* translators: 1: minimum value, 2: maximum value */
                __('Please enter a whole number between %1$d and %2$d.', 'portier'),
                $error['min'] ?? 0,
                $error['max'] ?? 0
            ),
            default => __('This value is invalid.', 'portier'),
        };
    }
}
