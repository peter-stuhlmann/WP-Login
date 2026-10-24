<?php

declare(strict_types=1);

namespace Portier\Tests\Integration;

use Portier\Admin\AdminPage;
use WP_UnitTestCase;

/**
 * @covers \Portier\Admin\AdminPage
 */
final class AdminPageTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $user = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($user);
        }
        wp_set_current_user($user);
        set_current_screen('dashboard');
    }

    public function test_one_submenu_entry_per_tab(): void
    {
        global $submenu;
        $page = new AdminPage();
        $page->menu();

        $slugs = array_column($submenu['portier'] ?? [], 2);
        $this->assertSame(array_keys(AdminPage::PAGES), $slugs);
    }

    public function test_the_page_slug_selects_the_tab(): void
    {
        $page = new AdminPage();
        $_GET['page'] = 'portier-registration';
        ob_start();
        $page->render();
        $html = (string) ob_get_clean();
        unset($_GET['page']);

        $this->assertStringContainsString('data-tab="registration"', $html);
        $this->assertStringContainsString('class="wp-header-end"', $html, 'Admin notices need their anchor.');
        $this->assertStringContainsString('<h1', $html, 'The page has a heading before the app loads.');
    }

    public function test_the_layout_of_the_tab_is_shown_while_the_app_loads(): void
    {
        $page = new AdminPage();
        $_GET['page'] = 'portier-design';
        ob_start();
        $page->render();
        $html = (string) ob_get_clean();
        unset($_GET['page']);

        $this->assertStringNotContainsString('portier-loading', $html, 'No loading text, placeholders instead.');
        $this->assertStringContainsString('class="portier-design"', $html, 'Same layout as the design tab.');
        $this->assertSame(8, substr_count($html, 'portier-skel--thumb'), 'One placeholder per template.');
        $this->assertStringContainsString('aria-busy="true"', $html);
        $this->assertStringContainsString('role="status"', $html, 'Screen readers hear that it loads.');
        $this->assertStringContainsString('class="portier-card portier-skel-card portier-skel-sized" style="--skel-hm:800px;--skel-h:808px" aria-hidden="true"', $html, 'Decorative, with the height of the real card.');
        $this->assertStringContainsString('data-tab="design"', $html, 'Remembered heights of this tab are used.');
        $this->assertLessThan(
            strpos($html, 'id="portier-admin-root"'),
            strpos($html, 'portier-skeleton-heights'),
            'The heights are in place before the placeholders paint.'
        );
        $this->assertStringContainsString('data-skel-col="0"', $html, 'Columns are numbered for the remembered heights.');
        $this->assertGreaterThan(
            strpos($html, 'id="portier-admin-root"'),
            strpos($html, 'portierSkeletonShapes;'),
            'The remembered shapes are drawn into the placeholders after them.'
        );

        // Real tab links: every tab is its own page, so they also work without JavaScript.
        $this->assertSame(5, substr_count($html, '<a class="portier-tab'));
        $this->assertStringContainsString('page=portier-security', $html);
        $this->assertMatchesRegularExpression('/is-active" href="[^"]*page=portier-design" aria-current="page"/', $html);
    }

    public function test_the_methods_placeholder_follows_the_settings(): void
    {
        $render = static fn (bool $magic): string => \Portier\Admin\Skeleton::render('methods', AdminPage::labels(), '1.0', ['magic_link' => $magic, 'providers' => 3]);

        $closed = $render(false);
        $this->assertSame(6, substr_count($closed, 'class="portier-method '), 'Password, magic link, passkeys and three providers.');
        $this->assertStringContainsString('--skel-hm:150px;--skel-h:98px', $closed, 'Magic link folded.');
        $this->assertStringContainsString('--skel-hm:273px;--skel-h:220px', $render(true), 'Magic link with its settings.');
        $this->assertStringContainsString('<h2 class="portier-section-title">', $closed, 'The intro is the real heading.');
    }

    public function test_the_settings_are_preloaded_with_the_page(): void
    {
        $page = new AdminPage();
        $page->menu();
        $page->enqueue('toplevel_page_portier');

        $after = implode("\n", (array) wp_scripts()->get_data('wp-api-fetch', 'after'));
        $this->assertStringContainsString('createPreloadingMiddleware', $after);
        $this->assertStringContainsString('\/portier\/v1\/settings', $after);
        $this->assertStringContainsString('"settings"', $after, 'The payload itself, not only the path.');
    }

    public function test_body_class_only_on_our_pages(): void
    {
        $page = new AdminPage();
        $page->menu();
        $this->assertStringNotContainsString('portier-admin-page', $page->bodyClass(''));

        set_current_screen('toplevel_page_portier');
        $this->assertStringContainsString('portier-admin-page', $page->bodyClass(''));
    }

    public function test_settings_link_on_the_plugins_screen(): void
    {
        $links = (new AdminPage())->actionLinks(['deactivate' => 'x']);
        $this->assertSame(['settings', 'deactivate'], array_keys($links));
        $this->assertStringContainsString('page=portier', $links['settings']);
    }
}
