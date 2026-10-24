<?php

declare(strict_types=1);

namespace Portier\Frontend;

use Portier\Design\Design;
use Portier\Design\Fonts;
use Portier\Settings\Settings;

/**
 * Styles, script and font preloads for the login screen (enqueued via core's
 * `login_enqueue_scripts`, which our layout fires).
 */
final class Assets
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function register(): void
    {
        add_action('login_enqueue_scripts', [$this, 'enqueue']);
        add_action('login_head', [$this, 'preloadFonts'], 1);
        // After the stylesheet, so custom CSS wins.
        add_action('login_head', [$this, 'customCss'], 99);
    }

    /**
     * Custom CSS from the design editor (sanitised on save, see CssSanitizer). In the live preview
     * the element always exists: the editor sends unsaved CSS into it (same origin only).
     */
    public function customCss(): void
    {
        if (! $this->isPortierScreen()) {
            return;
        }
        $css = Design::fromSettings($this->settings)->customCss;
        if ($css === '' && ! $this->settings->isPreview()) {
            return;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised CSS (CssSanitizer), no markup possible.
        echo '<style id="portier-custom-css">' . $css . "</style>\n";
    }

    /**
     * Only runs on our page: core's wp-login.php also fires this hook, and there it must not
     * receive our styles.
     */
    public function enqueue(): void
    {
        if (! $this->isPortierScreen()) {
            return;
        }
        $asset = $this->assetMeta('frontend');
        wp_enqueue_style('portier-login', plugins_url('build/frontend.css', PORTIER_FILE), [], $asset['version']);
        wp_style_add_data('portier-login', 'rtl', 'replace'); // build/frontend-rtl.css for right-to-left languages.
        wp_enqueue_script(
            'portier-login',
            plugins_url('build/frontend.js', PORTIER_FILE),
            [], // Dependency-free on purpose: fast and robust.
            $asset['version'],
            ['in_footer' => true, 'strategy' => 'defer']
        );
    }

    public function preloadFonts(): void
    {
        if (! $this->isPortierScreen()) {
            return;
        }
        // Declared here instead of in the bundled CSS: only the active template's fonts load, and
        // preload and @font-face URLs are guaranteed to be identical (no double download).
        $design = Design::fromSettings($this->settings);
        foreach ($design->fonts() as $font) {
            $url = Fonts::preloadUrl($font);
            if ($url !== '') {
                printf('<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url($url));
            }
        }
        // Built from the fixed font manifest and validated settings – no user markup.
        echo '<style id="portier-design">' . wp_strip_all_tags($design->css()) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
    }

    private function isPortierScreen(): bool
    {
        return ! empty($GLOBALS['wp']->query_vars[\Portier\Routing\LoginRoute::QUERY_VAR] ?? null);
    }

    /**
     * @return array{dependencies: list<string>, version: string}
     */
    private function assetMeta(string $entry): array
    {
        $file = PORTIER_DIR . '/build/' . $entry . '.asset.php';
        $meta = is_readable($file) ? require $file : [];
        return [
            'dependencies' => $meta['dependencies'] ?? [],
            'version' => $meta['version'] ?? PORTIER_VERSION,
        ];
    }
}
