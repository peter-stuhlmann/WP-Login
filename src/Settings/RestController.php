<?php

declare(strict_types=1);

namespace Portier\Settings;

use Portier\Auth\RegistrationService;
use Portier\Design\Fonts;
use Portier\Design\Templates;
use Portier\OAuth\OAuthClient;
use Portier\OAuth\OAuthException;
use Portier\OAuth\ProviderConfig;
use Portier\OAuth\Providers;
use Portier\Plugin;
use Portier\Routing\LoginRoute;
use Portier\Security\Crypto;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint for the admin app: GET/POST /wp-json/portier/v1/settings.
 */
final class RestController
{
    public const NAMESPACE = 'portier/v1';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'read'],
                'permission_callback' => [$this, 'canManage'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'write'],
                'permission_callback' => [$this, 'canManage'],
                'args' => [
                    'settings' => ['type' => 'object', 'required' => false],
                    'registration' => ['type' => 'object', 'required' => false],
                ],
            ],
        ]);
        // "Test connection" of a social login provider (Figma "Google-Konfiguration").
        register_rest_route(self::NAMESPACE, '/providers/(?P<id>[a-z0-9-]{2,32})/test', [
            'methods' => 'POST',
            'callback' => [$this, 'testProvider'],
            'permission_callback' => [$this, 'canManage'],
            'args' => [
                'client_id' => ['type' => 'string', 'required' => false, 'maxLength' => 300],
                'client_secret' => ['type' => 'string', 'required' => false, 'maxLength' => 500],
            ],
        ]);
    }

    /**
     * Checks a provider with the values in the form (unsaved ones too; an empty secret means
     * the stored one): can the server reach it, and does it accept client ID and secret?
     * The redirect URI can only be checked by a real login – the answer says so.
     */
    public function testProvider(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $provider = Providers::get((string) $request->get_param('id'));
        if ($provider === null) {
            return new WP_Error('portier_unknown_provider', __('Unknown provider.', 'portier'), ['status' => 404]);
        }
        $config = ProviderConfig::for($provider, $this->settings, Crypto::fromWordPress());
        $clientId = trim((string) $request->get_param('client_id'));
        $clientId = $config->source('client_id') === 'constant' || $clientId === '' ? $config->clientId() : $clientId;
        $secret = (string) $request->get_param('client_secret');
        $secret = $config->source('client_secret') === 'constant' || $secret === '' ? $config->clientSecret() : $secret;
        $route = new LoginRoute($this->settings->string('slug'), true);
        $client = new OAuthClient();

        $checks = [];
        try {
            $client->endpoints($provider);
            $checks[] = ['key' => 'reachable', 'status' => 'ok', 'message' => sprintf(
                /* translators: %s: provider name */
                __('%s can be reached.', 'portier'),
                $provider->label
            )];
        } catch (OAuthException) {
            $checks[] = ['key' => 'reachable', 'status' => 'fail', 'message' => sprintf(
                /* translators: %s: provider name */
                __('%s cannot be reached. Check the server’s internet connection or try again later.', 'portier'),
                $provider->label
            )];
            return new WP_REST_Response(['ok' => false, 'checks' => $checks]);
        }

        if ($clientId === '' || $secret === '') {
            $checks[] = ['key' => 'credentials', 'status' => 'skipped', 'message' => __('Enter client ID and secret first.', 'portier')];
        } else {
            try {
                $result = $client->checkClient($provider, $clientId, $secret, $route->callbackUrl($provider->id));
            } catch (OAuthException) {
                $result = 'unknown';
            }
            $checks[] = match ($result) {
                'accepted' => ['key' => 'credentials', 'status' => 'ok', 'message' => __('Client ID and secret were accepted.', 'portier')],
                'rejected' => ['key' => 'credentials', 'status' => 'fail', 'message' => sprintf(
                    /* translators: %s: provider name */
                    __('%s does not accept this client ID or secret. Please copy both again from the developer console.', 'portier'),
                    $provider->label
                )],
                default => ['key' => 'credentials', 'status' => 'unknown', 'message' => sprintf(
                    /* translators: %s: provider name */
                    __('The answer from %s was not clear. Please try a login yourself.', 'portier'),
                    $provider->label
                )],
            };
        }
        $checks[] = ['key' => 'redirect', 'status' => 'info', 'message' => __('The redirect URI is checked by the provider on the first real login.', 'portier')];

        $ok = ! in_array('fail', array_column($checks, 'status'), true) && ! in_array('skipped', array_column($checks, 'status'), true);
        return new WP_REST_Response(['ok' => $ok, 'checks' => $checks]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function read(): WP_REST_Response
    {
        return new WP_REST_Response($this->payload());
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function write(WP_REST_Request $request)
    {
        $input = (array) ($request->get_param('settings') ?? []);
        $registration = (array) ($request->get_param('registration') ?? []);
        if (array_key_exists('providers', $input)) {
            $input['providers'] = $this->mergeProviders((array) $input['providers']);
        }

        $errors = [];
        $sanitized = $this->settings->sanitize(array_merge($this->settings->all(), $input));
        foreach ($sanitized['errors'] as $key => $error) {
            $errors[$key] = Settings::errorMessage($error);
        }
        $roles = (array) $sanitized['values']['two_factor_roles'];
        if (array_diff($roles, array_keys($this->roles())) !== []) {
            $errors['two_factor_roles'] = __('This role does not exist.', 'portier');
        } elseif ($sanitized['values']['two_factor_mode'] === 'required' && $roles === []) {
            $errors['two_factor_roles'] = __('Please choose at least one role that requires two-factor authentication.', 'portier');
        }
        if (array_diff((array) $sanitized['values']['social_blocked_roles'], array_keys($this->roles())) !== []) {
            $errors['social_blocked_roles'] = __('This role does not exist.', 'portier');
        }
        $errors += $this->validateProviders((array) $sanitized['values']['providers']);
        $registrationError = $this->validateRegistration($registration);
        if ($registrationError !== null) {
            $errors['default_role'] = $registrationError;
        }
        foreach (['bg_image', 'bg_image_dark'] as $key) {
            $id = (int) $sanitized['values'][$key];
            if ($id > 0 && ! wp_attachment_is_image($id)) {
                $errors[$key] = __('Please choose an image from the media library.', 'portier');
            }
        }
        foreach ($this->networkLocked() as $key) {
            if ($sanitized['values'][$key] !== $this->settings->get($key)) {
                $errors[$key] = __('On this network only network administrators can change this setting.', 'portier');
            }
        }
        $previousCss = $this->settings->string('custom_css');
        $cssChanged = array_key_exists('custom_css', $input) && $sanitized['values']['custom_css'] !== $previousCss;
        if ($cssChanged && ! current_user_can('unfiltered_html')) {
            $errors['custom_css'] = __('Only administrators who may add unfiltered HTML can change custom CSS.', 'portier');
        }
        if ($errors !== []) {
            return new WP_Error('portier_invalid_settings', __('Some settings are invalid.', 'portier'), ['status' => 400, 'errors' => $errors]);
        }

        $this->settings->update($input);
        if ($cssChanged && $previousCss !== '') {
            $this->rememberCss($previousCss);
        }
        $this->saveRegistration($registration);
        return new WP_REST_Response($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $values = $this->settings->all();
        $route = new LoginRoute((string) $values['slug'], true);
        $canEditRegistration = ! is_multisite();

        $providers = [];
        $providerMeta = [];
        $crypto = Crypto::fromWordPress();
        foreach (Providers::all() as $id => $provider) {
            $config = ProviderConfig::for($provider, $this->settings, $crypto);
            // The secret never leaves the server; the admin only learns whether one is set.
            $providers[$id] = array_merge(Settings::providerValues((array) ($values['providers'][$id] ?? [])), ['client_secret' => '']);
            $providerMeta[] = [
                'id' => $id,
                'label' => $provider->label,
                'redirect_uri' => $route->callbackUrl($id),
                'console_url' => $provider->consoleUrl,
                'docs_url' => $provider->docsUrl,
                'openid' => $provider->isOpenIdConnect(),
                'client_id_source' => $config->source('client_id'),
                'client_secret_source' => $config->source('client_secret'),
                'constant_prefix' => 'PORTIER_' . strtoupper(str_replace('-', '_', $id)) . '_',
            ];
        }
        $values['providers'] = $providers;

        return [
            'settings' => $values,
            'registration' => [
                'open' => RegistrationService::isOpen(),
                'editable' => $canEditRegistration,
                'default_role' => (string) get_option('default_role', 'subscriber'),
                'roles' => $this->roles(),
            ],
            'meta' => [
                'login_url' => $route->url(),
                'kill_switch' => Plugin::killSwitchActive(),
                'privacy_policy_url' => get_privacy_policy_url(),
                'privacy_settings_url' => admin_url('options-privacy.php'),
                'multisite' => is_multisite(),
                // Account security settings only network administrators may change (multisite).
                'network_locked' => $this->networkLocked(),
                'pages' => $this->pages(),
                'providers' => $providerMeta,
                // WebAuthn needs HTTPS (or localhost): the admin explains when it is missing.
                'passkeys_available' => \Portier\Passkeys\PasskeyService::secureContext(),
                // Where the audit log is written (shown next to its switch).
                'audit_log_dir' => ltrim(str_replace(wp_normalize_path(ABSPATH), '', \Portier\Audit\LogFile::fromConfig()->directory()), '/') . '/',
                'templates' => $this->templates(),
                // Live preview of the design editor (see Plugin::preview()).
                'preview_url' => $route->url('login', ['portier_preview' => wp_create_nonce(Plugin::PREVIEW_NONCE)]),
                'preview_keys' => Settings::PREVIEW_KEYS,
                'fonts' => Fonts::choices(),
                'can_edit_css' => current_user_can('unfiltered_html'),
                'css_revisions' => $this->cssRevisions(),
                'images' => array_filter([
                    'bg_image' => $this->image((int) $values['bg_image']),
                    'bg_image_dark' => $this->image((int) $values['bg_image_dark']),
                ]),
            ],
        ];
    }

    /**
     * Provider input from the admin: a new secret is encrypted, an empty one keeps the stored
     * secret. Unknown providers are dropped.
     *
     * @param array<string, mixed> $input
     * @return array<string, array<string, mixed>>
     */
    private function mergeProviders(array $input): array
    {
        $stored = (array) $this->settings->get('providers');
        $crypto = Crypto::fromWordPress();
        $merged = [];
        foreach (Providers::all() as $id => $provider) {
            $current = Settings::providerValues((array) ($stored[$id] ?? []));
            $new = is_array($input[$id] ?? null) ? $input[$id] : [];
            $secret = is_string($new['client_secret'] ?? null) ? trim($new['client_secret']) : '';
            $new['client_secret'] = $secret !== '' ? $crypto->encrypt($secret) : $current['client_secret'];
            $merged[$id] = array_merge($current, $new);
        }
        return $merged;
    }

    /**
     * An enabled provider needs a client ID and a secret (from the settings or wp-config.php).
     *
     * @param array<string, mixed> $providers
     * @return array<string, string>
     */
    private function validateProviders(array $providers): array
    {
        $errors = [];
        foreach (Providers::all() as $id => $provider) {
            $values = Settings::providerValues((array) ($providers[$id] ?? []));
            if (! $values['enabled']) {
                continue;
            }
            if ($values['client_id'] === '' && ProviderConfig::constant($id, 'CLIENT_ID') === null) {
                $errors["providers.{$id}.client_id"] = __('Please enter the client ID.', 'portier');
            }
            if ($values['client_secret'] === '' && ProviderConfig::constant($id, 'CLIENT_SECRET') === null) {
                $errors["providers.{$id}.client_secret"] = __('Please enter the client secret.', 'portier');
            }
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $registration
     */
    private function validateRegistration(array $registration): ?string
    {
        if (! isset($registration['default_role'])) {
            return null;
        }
        $role = (string) $registration['default_role'];
        if (! array_key_exists($role, $this->roles())) {
            return __('This role does not exist.', 'portier');
        }
        if ($role === 'administrator') {
            return __('For security reasons new accounts cannot become administrators automatically.', 'portier');
        }
        return null;
    }

    /**
     * Registration maps to core options, so WordPress and Portier never disagree.
     *
     * @param array<string, mixed> $registration
     */
    private function saveRegistration(array $registration): void
    {
        if (is_multisite()) {
            return; // Network setting – managed under Network Admin › Settings.
        }
        if (array_key_exists('open', $registration)) {
            update_option('users_can_register', filter_var($registration['open'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
        if (isset($registration['default_role'])) {
            update_option('default_role', (string) $registration['default_role']);
        }
    }

    /**
     * Settings the current user may not change ({@see Settings::NETWORK_KEYS}).
     *
     * @return list<string>
     */
    private function networkLocked(): array
    {
        return is_multisite() && ! current_user_can('manage_network_options') ? Settings::NETWORK_KEYS : [];
    }

    /**
     * @return array<string, string>
     */
    private function roles(): array
    {
        $roles = [];
        foreach (wp_roles()->get_names() as $slug => $name) {
            $roles[(string) $slug] = translate_user_role((string) $name);
        }
        return $roles;
    }

    /**
     * What the design editor shows for a chosen background image.
     *
     * @return array{id: int, url: string, filename: string}|null
     */
    private function image(int $id): ?array
    {
        if ($id <= 0 || ! wp_attachment_is_image($id)) {
            return null;
        }
        return [
            'id' => $id,
            'url' => (string) wp_get_attachment_image_url($id, 'medium_large'),
            'filename' => wp_basename((string) get_attached_file($id)),
        ];
    }

    /** Earlier versions of the custom CSS (newest first, at most CSS_REVISIONS). */
    public const CSS_REVISIONS_OPTION = 'portier_css_revisions';
    private const CSS_REVISIONS = 10;

    /**
     * Keeps the replaced custom CSS as a revision.
     */
    private function rememberCss(string $css): void
    {
        $revisions = $this->cssRevisions();
        array_unshift($revisions, ['time' => time(), 'author' => (string) wp_get_current_user()->display_name, 'css' => $css]);
        update_option(self::CSS_REVISIONS_OPTION, array_slice($revisions, 0, self::CSS_REVISIONS), false);
    }

    /**
     * @return list<array{time: int, author: string, css: string}>
     */
    private function cssRevisions(): array
    {
        $stored = get_option(self::CSS_REVISIONS_OPTION, []);
        $revisions = [];
        foreach (is_array($stored) ? $stored : [] as $revision) {
            if (is_array($revision) && is_string($revision['css'] ?? null)) {
                $revisions[] = [
                    'time' => (int) ($revision['time'] ?? 0),
                    'author' => (string) ($revision['author'] ?? ''),
                    'css' => $revision['css'],
                ];
            }
        }
        return $revisions;
    }

    /**
     * Templates for the design editor, with their default texts (shown as placeholders).
     *
     * @return list<array{id: string, label: string, description: string, dark: string, panel: bool, font: string, defaults: array{headline: string, text: string}}>
     */
    private function templates(): array
    {
        $list = [];
        foreach (Templates::all() as $id => $template) {
            $list[] = [
                'id' => $id,
                'label' => $template['label'],
                'description' => $template['description'],
                'dark' => $template['dark'],
                'panel' => $template['panel'] || $id === 'enterprise',
                'font' => $template['ui'],
                'defaults' => Templates::defaultTexts($id, (string) get_bloginfo('name'), (string) get_bloginfo('description')),
            ];
        }
        return $list;
    }

    /**
     * Published pages for the imprint / accessibility statement selects.
     *
     * @return list<array{id: int, title: string}>
     */
    private function pages(): array
    {
        $pages = get_pages(['post_status' => 'publish', 'sort_column' => 'post_title', 'number' => 300]);
        return array_values(array_map(
            static fn (\WP_Post $page): array => ['id' => $page->ID, 'title' => $page->post_title !== '' ? $page->post_title : sprintf('#%d', $page->ID)],
            is_array($pages) ? $pages : []
        ));
    }
}
