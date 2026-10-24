<?php

declare(strict_types=1);

namespace Portier\Admin;

use Portier\Design\Templates;
use Portier\OAuth\Providers;
use Portier\Plugin;
use Portier\Settings\RestController;

/**
 * "Portier" menu in wp-admin with one submenu entry per settings tab (as in the design).
 * All entries render the same React app; the page slug decides which tab opens first.
 */
final class AdminPage
{
    /** Page slug => tab name. The first entry is the top-level page. */
    public const PAGES = [
        'portier' => 'methods',
        'portier-security' => 'security',
        'portier-design' => 'design',
        'portier-registration' => 'registration',
        'portier-advanced' => 'advanced',
    ];

    /** @var list<string> Hook suffixes of our admin pages. */
    private array $hooks = [];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('admin_body_class', [$this, 'bodyClass']);
        add_filter('plugin_action_links_' . plugin_basename(PORTIER_FILE), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        $title = __('Portier – Login settings', 'portier');
        $this->hooks[] = (string) add_menu_page($title, __('Portier', 'portier'), 'manage_options', 'portier', [$this, 'render'], $this->menuIcon(), 81);

        foreach (self::labels() as $slug => $label) {
            $this->hooks[] = (string) add_submenu_page('portier', $title, $label, 'manage_options', $slug, [$this, 'render']);
        }
    }

    public function render(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects the tab to show.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'portier';
        $tab = self::PAGES[$page] ?? 'methods';

        // Admin notices are moved here by core (after .wp-header-end).
        echo '<div class="wrap portier-admin-wrap"><hr class="wp-header-end"></div>';
        // The layout of the tab with placeholders, until the app takes over (and the page's <h1>
        // and tab links without JS). Skeleton::render() escapes everything it outputs.
        $settings = Plugin::instance()->settings();
        $template = Templates::all()[Templates::id((string) $settings->get('template'))] ?? [];
        $state = [
            'magic_link' => (bool) $settings->get('magic_link'),
            'providers' => count(Providers::all()),
            'panel' => (bool) ($template['panel'] ?? false),
        ];
        // Remembered heights first: they must be in place before the placeholders paint.
        wp_print_inline_script_tag(Skeleton::script(), ['data-tab' => $tab]);
        printf(
            '<div id="portier-admin-root" data-tab="%1$s" data-version="%2$s">%3$s</div>',
            esc_attr($tab),
            esc_attr(PORTIER_VERSION),
            Skeleton::render($tab, self::labels(), PORTIER_VERSION, $state) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside.
        );
        wp_print_inline_script_tag(Skeleton::shapesScript());
        printf(
            '<noscript><div class="notice notice-error"><p>%s</p></div></noscript>',
            esc_html__('The Portier settings need JavaScript. Please enable it in your browser.', 'portier')
        );
    }

    public function enqueue(string $hook): void
    {
        if (! in_array($hook, $this->hooks, true)) {
            return;
        }
        $file = PORTIER_DIR . '/build/admin.asset.php';
        $asset = is_readable($file) ? require $file : ['dependencies' => [], 'version' => PORTIER_VERSION];

        // Media library modal for the background image (Design tab).
        wp_enqueue_media();
        // WordPress' code editor (CodeMirror + CSSLint) for custom CSS. False when the user
        // switched off syntax highlighting in the profile: the plain text field stays.
        $codeEditor = current_user_can('unfiltered_html') ? wp_enqueue_code_editor([
            'type' => 'text/css',
            // CSSLint while typing (off by default in core): errors in the gutter and our warning.
            'codemirror' => ['indentUnit' => 4, 'tabSize' => 4, 'lint' => true, 'gutters' => ['CodeMirror-lint-markers']],
        ]) : false;
        $dependencies = array_merge($asset['dependencies'], $codeEditor !== false ? ['code-editor'] : []);
        wp_enqueue_script('portier-admin', plugins_url('build/admin.js', PORTIER_FILE), $dependencies, $asset['version'], true);
        wp_add_inline_script('portier-admin', 'window.portierCodeEditor = ' . wp_json_encode($codeEditor) . ';', 'before');
        wp_set_script_translations('portier-admin', 'portier', PORTIER_DIR . '/languages');
        // The settings come with the page (like the block editor's preloading): the app can
        // replace the placeholders at once instead of waiting for a second request.
        $preload = rest_preload_api_request([], '/' . RestController::NAMESPACE . '/settings');
        wp_add_inline_script(
            'wp-api-fetch',
            sprintf('wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );', (string) wp_json_encode($preload)),
            'after'
        );
        if (is_readable(PORTIER_DIR . '/build/admin.css')) {
            wp_enqueue_style('portier-admin', plugins_url('build/admin.css', PORTIER_FILE), [], $asset['version']);
            wp_style_add_data('portier-admin', 'rtl', 'replace');
        }
    }

    /**
     * Menu and tab labels.
     *
     * @return array<string, string> Page slug => label.
     */
    public static function labels(): array
    {
        return [
            'portier' => __('Login methods', 'portier'),
            'portier-security' => __('Security', 'portier'),
            'portier-design' => __('Design', 'portier'),
            'portier-registration' => __('Registration', 'portier'),
            'portier-advanced' => __('Advanced', 'portier'),
        ];
    }

    public function bodyClass(string $classes): string
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && in_array($screen->id, $this->hooks, true)) {
            $classes .= ' portier-admin-page';
        }
        return $classes;
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function actionLinks(array $links): array
    {
        $settings = sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=portier')), esc_html__('Settings', 'portier'));
        return ['settings' => $settings] + $links;
    }

    private function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" '
            . 'd="M10 2a6 6 0 0 0-6 6v10h3v-6.5a3 3 0 0 1 6 0V18h3V8a6 6 0 0 0-6-6Zm0 9.2a1.3 1.3 0 0 0-.65 2.43V15h1.3v-1.37A1.3 1.3 0 0 0 10 11.2Z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menu icon data URI.
    }
}
