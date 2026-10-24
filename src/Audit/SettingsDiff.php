<?php

declare(strict_types=1);

namespace Portier\Audit;

use Portier\Design\Fonts;
use Portier\Design\Templates;
use Portier\OAuth\Providers;
use Portier\Settings\Settings;

/**
 * Turns a settings change into readable log messages: "Passkeys enabled",
 * "Login with Google disabled", "Minimum password length: 12 → 14".
 *
 * Secrets and custom CSS never appear: only that they changed (the CSS with its length).
 * Unknown keys (added by a later version) are logged with their key and value.
 */
final class SettingsDiff
{
    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<string>
     */
    public static function messages(array $old, array $new): array
    {
        $messages = [];
        foreach ($new as $key => $value) {
            $before = $old[$key] ?? null;
            if ($before === $value) {
                continue;
            }
            if ($key === 'providers') {
                array_push($messages, ...self::providers(is_array($before) ? $before : [], is_array($value) ? $value : []));
                continue;
            }
            $messages[] = self::message((string) $key, $before, $value);
        }
        return $messages;
    }

    private static function message(string $key, mixed $before, mixed $after): string
    {
        $label = self::labels()[$key] ?? $key;

        // Switches: "… enabled" / "… disabled" (XML-RPC is a "turn off" switch).
        if (is_bool($after)) {
            if ($key === 'disable_xmlrpc') {
                return $after ? __('XML-RPC switched off', 'portier') : __('XML-RPC switched on', 'portier');
            }
            /* translators: %s: name of a setting, e.g. "Passkeys" */
            return sprintf($after ? __('%s enabled', 'portier') : __('%s disabled', 'portier'), $label);
        }
        if ($key === 'custom_css') {
            /* translators: 1: old length, 2: new length (characters) */
            return sprintf(__('Custom CSS changed (%1$d → %2$d characters)', 'portier'), mb_strlen((string) $before), mb_strlen((string) $after));
        }
        /* translators: 1: name of a setting, 2: old value, 3: new value */
        return sprintf(__('%1$s: %2$s → %3$s', 'portier'), $label, self::value($key, $before), self::value($key, $after));
    }

    /**
     * Provider changes, one message each: "Login with Google enabled", "Google: client secret changed".
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return list<string>
     */
    private static function providers(array $old, array $new): array
    {
        $messages = [];
        $labels = [];
        foreach (Providers::all() as $id => $provider) {
            $labels[$id] = $provider->label;
        }
        foreach ($new as $id => $values) {
            $name = $labels[$id] ?? (string) $id;
            // A provider configured for the first time is compared with its defaults.
            $before = array_merge(Settings::providerValues([]), is_array($old[$id] ?? null) ? $old[$id] : []);
            $values = is_array($values) ? $values : [];
            foreach ($values as $field => $value) {
                $previous = $before[$field] ?? null;
                if ($previous === $value) {
                    continue;
                }
                $messages[] = match ($field) {
                    /* translators: %s: provider, e.g. Google */
                    'enabled' => sprintf($value ? __('Login with %s enabled', 'portier') : __('Login with %s disabled', 'portier'), $name),
                    /* translators: %s: provider */
                    'client_secret' => sprintf(__('%s: client secret changed', 'portier'), $name),
                    /* translators: 1: provider, 2: old client ID, 3: new client ID */
                    'client_id' => sprintf(__('%1$s: client ID %2$s → %3$s', 'portier'), $name, self::text($previous), self::text($value)),
                    /* translators: 1: provider, 2: old policy, 3: new policy */
                    'link' => sprintf(__('%1$s: link accounts %2$s → %3$s', 'portier'), $name, self::linkPolicy($previous), self::linkPolicy($value)),
                    'signup' => sprintf(
                        $value
                            /* translators: %s: provider */
                            ? __('%s: new accounts allowed', 'portier')
                            /* translators: %s: provider */
                            : __('%s: existing accounts only', 'portier'),
                        $name
                    ),
                    /* translators: 1: provider, 2: setting key, 3: new value */
                    default => sprintf(__('%1$s: %2$s changed to %3$s', 'portier'), $name, (string) $field, self::text($value)),
                };
            }
        }
        return $messages;
    }

    private static function value(string $key, mixed $value): string
    {
        return match ($key) {
            'slug' => '/' . self::text($value) . '/',
            'core_login_behavior' => $value === 'block' ? __('show 404', 'portier') : __('redirect to the new address', 'portier'),
            'login_steps' => $value === 'two' ? __('two steps', 'portier') : __('one step', 'portier'),
            'two_factor_mode' => match ($value) {
                'off' => __('off', 'portier'),
                'required' => __('required for roles', 'portier'),
                default => __('optional', 'portier'),
            },
            'two_factor_roles', 'social_blocked_roles' => self::roles($value),
            'method_order' => self::methods($value),
            'template' => (string) (Templates::all()[(string) $value]['label'] ?? self::text($value)),
            'color_scheme' => match ($value) {
                'light' => __('light', 'portier'),
                'dark' => __('dark', 'portier'),
                default => __('auto (system)', 'portier'),
            },
            'custom_font' => $value === '' || $value === null ? __('template default', 'portier') : (string) (Fonts::choices()[(string) $value] ?? $value),
            'custom_primary', 'custom_accent', 'custom_background', 'design_headline', 'design_text' => $value === '' || $value === null
                ? __('template default', 'portier')
                : self::text($value),
            'custom_radius' => (int) $value < 0 ? __('template default', 'portier') : (int) $value . ' px',
            'bg_image', 'bg_image_dark' => self::image($value),
            'imprint_page', 'accessibility_page' => (int) $value > 0
                ? (string) get_the_title((int) $value) . ' (#' . (int) $value . ')'
                : __('none', 'portier'),
            'trusted_proxy_header' => $value === '' || $value === null ? __('direct connection (REMOTE_ADDR)', 'portier') : self::text($value),
            default => self::text($value),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'enabled' => __('Custom login', 'portier'),
            'slug' => __('Login address', 'portier'),
            'core_login_behavior' => __('Calls of wp-login.php', 'portier'),
            'login_steps' => __('Login screen', 'portier'),
            'min_password_length' => __('Minimum password length', 'portier'),
            'require_privacy_consent' => __('Privacy consent at registration', 'portier'),
            'language_switcher' => __('Language switcher', 'portier'),
            'imprint_page' => __('Imprint page', 'portier'),
            'accessibility_page' => __('Accessibility statement page', 'portier'),
            'rate_limit_attempts' => __('Failed attempts until pause', 'portier'),
            'rate_limit_lockout_minutes' => __('First pause (minutes)', 'portier'),
            'rate_limit_account_attempts' => __('Failed attempts per account and hour', 'portier'),
            'trusted_proxy_header' => __('Visitor IP address', 'portier'),
            'two_factor_mode' => __('Two-factor authentication', 'portier'),
            'two_factor_roles' => __('Two-factor authentication required for', 'portier'),
            'trust_device_days' => __('Trust device (days)', 'portier'),
            'magic_link' => __('Magic link', 'portier'),
            'magic_link_minutes' => __('Magic link valid for (minutes)', 'portier'),
            'passkeys' => __('Passkeys', 'portier'),
            'audit_log' => __('Audit log', 'portier'),
            'method_order' => __('Order of the methods below the form', 'portier'),
            'social_blocked_roles' => __('Social login blocked for', 'portier'),
            'template' => __('Template', 'portier'),
            'color_scheme' => __('Colour scheme', 'portier'),
            'design_headline' => __('Headline', 'portier'),
            'design_text' => __('Text', 'portier'),
            'custom_primary' => __('Primary colour', 'portier'),
            'custom_accent' => __('Accent colour', 'portier'),
            'custom_background' => __('Background colour', 'portier'),
            'custom_radius' => __('Corner radius', 'portier'),
            'custom_font' => __('Font', 'portier'),
            'bg_image' => __('Background image', 'portier'),
            'bg_image_dark' => __('Background image for dark mode', 'portier'),
            'bg_dark' => __('Own background image for dark mode', 'portier'),
            'bg_focus_x' => __('Focus point horizontal (%)', 'portier'),
            'bg_focus_y' => __('Focus point vertical (%)', 'portier'),
            'bg_overlay' => __('Overlay transparency (%)', 'portier'),
            'bg_mobile' => __('Background image on mobile devices', 'portier'),
        ];
    }

    private static function roles(mixed $roles): string
    {
        if (! is_array($roles) || $roles === []) {
            return __('nobody', 'portier');
        }
        $names = wp_roles()->get_names();
        return implode(', ', array_map(static fn ($role): string => translate_user_role($names[(string) $role] ?? (string) $role), $roles));
    }

    private static function methods(mixed $order): string
    {
        if (! is_array($order) || $order === []) {
            return __('default', 'portier');
        }
        $labels = ['passkey' => __('Passkeys', 'portier')];
        foreach (Providers::all() as $id => $provider) {
            $labels[$id] = $provider->label;
        }
        return implode(', ', array_map(static fn ($id): string => $labels[(string) $id] ?? (string) $id, $order));
    }

    private static function image(mixed $id): string
    {
        $id = (int) $id;
        if ($id <= 0) {
            return __('none', 'portier');
        }
        $file = get_attached_file($id);
        return (is_string($file) && $file !== '' ? wp_basename($file) : __('unknown', 'portier')) . ' (#' . $id . ')';
    }

    private static function linkPolicy(mixed $policy): string
    {
        return $policy === 'never' ? __('never', 'portier') : __('verified email only', 'portier');
    }

    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('on', 'portier') : __('off', 'portier');
        }
        if (is_array($value)) {
            return implode(', ', array_map([self::class, 'text'], $value));
        }
        if ($value === null || $value === '') {
            return '–';
        }
        return is_scalar($value) ? (string) $value : '–';
    }
}
