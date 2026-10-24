<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Design\Design;
use Portier\Design\Fonts;
use Portier\Design\Templates;
use Portier\Frontend\Assets;
use Portier\Http\Request;
use Portier\Plugin;
use Portier\Settings\Settings;
use Portier\Tests\Support\ScreenRequests;
use WP_UnitTestCase;

/**
 * Templates, fonts, the page layout per template and the design editor's live preview.
 *
 * @covers \Portier\Design\Design
 * @covers \Portier\Design\Templates
 * @covers \Portier\Design\Fonts
 * @covers \Portier\Frontend\Assets
 * @covers \Portier\Plugin::preview
 * @covers \Portier\Frontend\Controller::preview
 */
final class DesignTest extends WP_UnitTestCase
{
    use ScreenRequests;

    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
        $this->jar = [];
        update_option('blogname', 'Nordlicht');
        update_option('blogdescription', '');
        update_option(Settings::OPTION, []);
    }

    public function tear_down(): void
    {
        $this->remove_added_uploads();
        parent::tear_down();
    }

    /**
     * Design for exactly these settings (nothing left over from earlier calls).
     */
    private function design(array $settings): Design
    {
        update_option(Settings::OPTION, $settings);
        return Design::fromSettings(new Settings());
    }

    public function test_aurora_is_the_default_and_follows_the_system(): void
    {
        $design = $this->design([]);
        $this->assertSame('aurora', $design->template);
        $this->assertSame('auto', $design->scheme);
        $this->assertSame('light dark', $design->colorSchemeMeta());
    }

    public function test_the_colour_scheme_only_applies_to_templates_with_a_dark_variant(): void
    {
        $this->assertSame('dark', $this->design(['color_scheme' => 'dark'])->scheme);
        $this->assertSame('light', $this->design(['template' => 'pure', 'color_scheme' => 'dark'])->scheme);
        $this->assertSame('dark', $this->design(['template' => 'midnight', 'color_scheme' => 'light'])->scheme);
    }

    public function test_settings_are_sanitised(): void
    {
        $values = (new Settings())->sanitize([
            'template' => 'hacked',
            'color_scheme' => 'neon',
            'design_headline' => "  <b>Hello</b>\n  world  ",
            'design_text' => str_repeat('x', 400),
        ])['values'];
        $this->assertSame('aurora', $values['template']);
        $this->assertSame('auto', $values['color_scheme']);
        $this->assertSame('Hello world', $values['design_headline']);
        $this->assertSame(300, mb_strlen($values['design_text']));
        $this->assertSame(Templates::ids(), array_keys(Templates::all()));
    }

    public function test_panel_texts_have_real_defaults_and_can_be_changed(): void
    {
        $studio = $this->design(['template' => 'studio']);
        $this->assertSame('Welcome to Nordlicht', $studio->headline, 'No invented marketing claims.');
        $this->assertTrue($studio->panel);
        $custom = $this->design(['template' => 'studio', 'design_headline' => 'Create what lasts.']);
        $this->assertSame('Create what lasts.', $custom->headline);
        $this->assertFalse($this->design(['template' => 'bold'])->panel);
    }

    public function test_only_the_fonts_of_the_template_are_loaded(): void
    {
        $journal = $this->design(['template' => 'journal']);
        $this->assertSame(['geist', 'instrument-serif'], $journal->fonts());
        $css = $journal->css();
        $this->assertStringContainsString("font-family:'Instrument Serif'", $css);
        $this->assertStringContainsString('font-style:italic', $css);
        $this->assertStringContainsString('unicode-range:U+', $css);
        $this->assertStringNotContainsString('Archivo', $css);
        $this->assertStringContainsString(':root[data-portier-template]{--portier-font:"Geist"', $css);
        $this->assertStringContainsString('--portier-font-display:"Instrument Serif", Georgia', $css);
    }

    public function test_fonts_are_self_hosted(): void
    {
        foreach (array_keys(Fonts::choices()) as $font) {
            $url = Fonts::preloadUrl($font);
            $this->assertStringStartsWith(plugins_url('assets/fonts/', PORTIER_FILE), $url, $font);
            $this->assertFileExists(PORTIER_DIR . '/assets/fonts/' . substr($url, strlen(plugins_url('assets/fonts/', PORTIER_FILE))));
            $this->assertStringNotContainsString('latin-ext', $url, 'The basic subset is preloaded.');
        }
        $this->assertSame('', Fonts::faces(['does-not-exist']));
    }

    public function test_the_head_preloads_the_template_fonts(): void
    {
        update_option(Settings::OPTION, ['template' => 'bold']);
        Plugin::instance()->settings()->flushCache();
        $GLOBALS['wp']->query_vars['portier'] = '1';
        ob_start();
        (new Assets(Plugin::instance()->settings()))->preloadFonts();
        $head = (string) ob_get_clean();
        unset($GLOBALS['wp']->query_vars['portier']);

        $this->assertSame(2, substr_count($head, 'rel="preload"'));
        $this->assertStringContainsString('archivo-black-latin-400-normal.woff2', $head);
        $this->assertStringContainsString('<style id="portier-design">', $head);
    }

    public function test_the_layout_carries_template_and_panel(): void
    {
        update_option(Settings::OPTION, ['template' => 'journal', 'design_headline' => 'Hello there reader']);
        $page = $this->send('GET')->body;
        $this->assertStringContainsString('data-portier-template="journal"', $page);
        $this->assertStringContainsString('data-portier-scheme="light"', $page);
        $this->assertStringContainsString('<meta name="color-scheme" content="light">', $page);
        $this->assertMatchesRegularExpression('#<aside class="portier-panel" aria-label="About Nordlicht">#', $page);
        $this->assertMatchesRegularExpression('#Hello there <em>reader</em>#', $page, 'The last word can be accented.');

        update_option(Settings::OPTION, ['template' => 'enterprise', 'design_headline' => 'Staff portal']);
        $page = $this->send('GET')->body;
        $this->assertStringContainsString('<span class="portier-brand__label">Staff portal</span>', $page);
        $this->assertStringNotContainsString('portier-panel"', $page);
    }

    public function test_custom_colours_radius_and_font_override_the_template(): void
    {
        $design = $this->design(['template' => 'pure', 'custom_primary' => '#0B7A5A', 'custom_accent' => '#c2410c', 'custom_radius' => 6, 'custom_font' => 'manrope']);
        $css = $design->css();
        $this->assertStringContainsString('--portier-color-primary:#0b7a5a;', $css);
        $this->assertStringContainsString('--portier-color-on-primary:#ffffff;', $css);
        $this->assertStringContainsString('--portier-color-focus:#c2410c;', $css);
        $this->assertStringContainsString('--portier-radius-field:6px;', $css);
        $this->assertStringContainsString('--portier-radius-card:12px;', $css);
        $this->assertSame(['manrope'], $design->fonts());
        $this->assertTrue($design->isCustomised());
        $this->assertFalse($this->design(['template' => 'pure'])->isCustomised());
    }

    public function test_aurora_dark_mode_keeps_its_tested_colours(): void
    {
        $auto = $this->design(['custom_primary' => '#0b7a5a'])->css();
        $this->assertStringContainsString('@media (prefers-color-scheme: light){', $auto);
        $dark = $this->design(['custom_primary' => '#0b7a5a', 'color_scheme' => 'dark'])->css();
        $this->assertStringNotContainsString('#0b7a5a', $dark);
        $midnight = $this->design(['template' => 'midnight', 'custom_primary' => '#38bdf8'])->css();
        $this->assertStringContainsString('--portier-color-primary:#38bdf8;', $midnight, 'A dark-only template uses them.');
    }

    public function test_invalid_design_values_are_reported(): void
    {
        $result = (new Settings())->sanitize(['custom_primary' => 'red', 'custom_radius' => 99, 'custom_font' => 'comic-sans']);
        $this->assertSame('color', $result['errors']['custom_primary']['code']);
        $this->assertSame('out_of_range', $result['errors']['custom_radius']['code']);
        $this->assertSame('', $result['values']['custom_font']);
    }

    public function test_custom_css_is_printed_after_the_stylesheet_and_cleaned(): void
    {
        update_option(Settings::OPTION, ['custom_css' => '.portier-title{color:#123456}</style><script>x</script>']);
        Plugin::instance()->settings()->flushCache();
        $GLOBALS['wp']->query_vars['portier'] = '1';
        ob_start();
        (new Assets(Plugin::instance()->settings()))->customCss();
        $out = (string) ob_get_clean();
        unset($GLOBALS['wp']->query_vars['portier']);
        $this->assertStringContainsString('<style id="portier-custom-css">.portier-title{color:#123456}', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertSame(1, substr_count($out, '</style>'));
    }

    public function test_the_preview_shows_unsaved_colours(): void
    {
        $this->becomeAdmin();
        $response = $this->preview([
            'portier_preview' => wp_create_nonce(Plugin::PREVIEW_NONCE),
            'preview' => ['template' => 'pure', 'custom_primary' => '#0b7a5a', 'custom_css' => 'body{display:none}'],
        ]);
        $this->assertStringContainsString('data-portier-preview', $response->body);
        $this->assertSame('', Plugin::instance()->settings()->string('custom_css'), 'CSS never travels through the URL.');
        $this->assertSame('#0b7a5a', Plugin::instance()->settings()->string('custom_primary'));
        $this->assertArrayNotHasKey('custom_primary', (array) get_option(Settings::OPTION), 'Not saved.');
        Plugin::instance()->settings()->flushCache();
    }

    private function image(string $file = 'background.jpg'): int
    {
        return self::factory()->attachment->create_upload_object(PORTIER_DIR . '/tests/e2e/fixtures/' . $file);
    }

    public function test_the_background_image_lies_under_the_template_overlay(): void
    {
        $id = $this->image();
        $design = $this->design(['bg_image' => $id, 'bg_overlay' => 30, 'bg_focus_x' => 20, 'bg_focus_y' => 80]);
        $this->assertNotNull($design->photo);
        $css = $design->css();
        $this->assertStringContainsString('--portier-photo:url("' . wp_get_attachment_image_url($id, 'full') . '")', $css);
        $this->assertStringContainsString('--portier-photo-position:20% 80%;', $css);
        $this->assertStringContainsString('--portier-overlay:0.7;', $css, '30 % transparency = 70 % template.');
        $this->assertStringNotContainsString('data-portier-scheme="dark"', $css, 'No dark image configured.');
    }

    public function test_only_real_images_count(): void
    {
        $post = self::factory()->post->create();
        $this->assertNull($this->design(['bg_image' => $post])->photo);
        $this->assertNull($this->design(['bg_image' => 999999])->photo);
        $this->assertSame(50, Settings::defaults()['bg_overlay'], 'Default 50 % (Figma).');
    }

    public function test_dark_images_need_the_switch_and_a_dark_capable_template(): void
    {
        $light = $this->image();
        $dark = $this->image('background-dark.jpg');
        $off = $this->design(['bg_image' => $light, 'bg_image_dark' => $dark, 'bg_dark' => false])->css();
        $this->assertStringNotContainsString('prefers-color-scheme: dark', $off);

        $on = $this->design(['bg_image' => $light, 'bg_image_dark' => $dark, 'bg_dark' => true])->css();
        $this->assertStringContainsString(':root[data-portier-photo][data-portier-scheme="dark"]', $on);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark){:root[data-portier-photo][data-portier-scheme="auto"]', $on);

        $pure = $this->design(['template' => 'pure', 'bg_image' => $light, 'bg_image_dark' => $dark, 'bg_dark' => true]);
        $this->assertSame('', $pure->photo['dark'], 'Pure has no dark look.');
    }

    public function test_the_layout_carries_the_image_layer(): void
    {
        update_option(Settings::OPTION, ['bg_image' => $this->image(), 'bg_mobile' => false]);
        $page = $this->send('GET')->body;
        $this->assertStringContainsString('data-portier-photo="desktop"', $page);
        $this->assertStringContainsString('<div class="portier-photo" aria-hidden="true"></div>', $page);

        update_option(Settings::OPTION, []);
        $page = $this->send('GET')->body;
        $this->assertStringNotContainsString('data-portier-photo', $page);
        $this->assertStringNotContainsString('portier-photo"', $page);
    }

    public function test_normal_pages_cannot_be_framed(): void
    {
        $headers = $this->send('GET')->headers();
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }

    private function preview(array $query): \Portier\Http\Response
    {
        $request = new Request('GET', $query, [], ['REMOTE_ADDR' => '203.0.113.9'], []);
        return Plugin::instance()->preview($request);
    }

    private function becomeAdmin(): void
    {
        $user = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($user);
        }
        wp_set_current_user($user);
    }

    public function test_the_preview_is_only_for_administrators_with_a_nonce(): void
    {
        $this->assertSame(403, $this->preview(['portier_preview' => 'x'])->status, 'Guests.');

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame(403, $this->preview(['portier_preview' => wp_create_nonce(Plugin::PREVIEW_NONCE)])->status, 'Editors.');

        $this->becomeAdmin();
        $this->assertSame(403, $this->preview(['portier_preview' => 'forged'])->status, 'Forged nonce.');
        $this->assertSame(200, $this->preview(['portier_preview' => wp_create_nonce(Plugin::PREVIEW_NONCE)])->status);
    }

    public function test_the_preview_applies_unsaved_values_without_saving_them(): void
    {
        $this->becomeAdmin();
        $response = $this->preview([
            'portier_preview' => wp_create_nonce(Plugin::PREVIEW_NONCE),
            'preview' => ['template' => 'midnight', 'slug' => 'hijacked', 'enabled' => '0'],
        ]);
        $this->assertStringContainsString('data-portier-template="midnight"', $response->body);
        $this->assertStringContainsString("frame-ancestors 'self'", $response->headers()['Content-Security-Policy']);
        $this->assertSame('SAMEORIGIN', $response->headers()['X-Frame-Options']);

        $stored = (array) get_option(Settings::OPTION);
        $this->assertArrayNotHasKey('template', $stored, 'Nothing is saved.');
        $this->assertSame('login', Plugin::instance()->settings()->string('slug'), 'Only design keys are previewed.');
        $this->assertTrue(Plugin::instance()->settings()->bool('enabled'));
        Plugin::instance()->settings()->flushCache();
    }

    /**
     * @dataProvider states
     */
    public function test_the_preview_shows_every_screen_even_when_logged_in(string $state, string $expect): void
    {
        $this->becomeAdmin();
        $response = $this->preview([
            'portier_preview' => wp_create_nonce(Plugin::PREVIEW_NONCE),
            'state' => $state,
            'preview' => ['login_steps' => 'two'],
        ]);
        $this->assertContains($response->status, [200, 422]);
        $this->assertStringContainsString($expect, $response->body);
        Plugin::instance()->settings()->flushCache();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function states(): array
    {
        return [
            'start' => ['login', 'name="portier_step" value="identify"'],
            'password' => ['password', 'portier-account'],
            'error' => ['error', 'portier-errors'],
            '2fa' => ['2fa', 'autocomplete="one-time-code"'],
            'lost password' => ['lostpassword', 'name="user_login"'],
        ];
    }
}
