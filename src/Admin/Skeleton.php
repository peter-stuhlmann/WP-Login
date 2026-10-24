<?php

declare(strict_types=1);

namespace Portier\Admin;

/**
 * Server-rendered placeholder of the settings screen.
 *
 * Shown from the first byte until the React app takes over: the real header, real tab links
 * (they also work without JavaScript, every tab is its own admin page) and grey placeholder
 * shapes in the layout of the requested tab. Nothing should move when the app appears:
 *
 * - Header, tabs and the intro of the methods tab are the real elements.
 * - Every placeholder card has the height of the real card (measured, phone / from 783 px).
 * - The app remembers the real heights per tab and window width in the browser (skeleton.js).
 *   The inline script from script(), printed before the placeholders, turns them into a style
 *   sheet – so even the very first paint has them, and from the second visit on the
 *   placeholders match exactly, also after settings changed the layout.
 *
 * The shapes are decorative (aria-hidden); screen readers hear one "Loading settings…" status.
 */
final class Skeleton
{
    /**
     * Card heights per tab: columns => cards => [phone, from 783 px] in px.
     * Measured on the real tabs (assets/src/admin/tabs) with the default settings.
     */
    private const CARDS = [
        'security' => [[[509, 369], [392, 347], [172, 180]], [[548, 440], [184, 175]]],
        'registration' => [[[367, 311]], [[188, 177]]],
        'advanced' => [[[144, 135]], [[312, 301]]],
        'design' => [[[800, 808], [232, 204], [675, 647], [485, 493], [456, 428]]],
        'design-texts' => [[[300, 290]]],
        'methods-aside' => [[[181, 208], [236, 263]]],
    ];

    /** Method rows of the methods tab: [phone, from 783 px] in px. */
    private const ROWS = [
        'password' => [233, 199],
        'magic-open' => [273, 220],
        'magic' => [150, 98],
        'provider' => [131, 77],
    ];

    /** Design preview (toolbar and phone): [phone, from 783 px] in px. */
    private const PREVIEW = [672, 774];

    /**
     * @param string               $tab     Active tab (AdminPage::PAGES value).
     * @param array<string,string> $tabs    Page slug => tab label.
     * @param string               $version Plugin version for the header badge.
     * @param array{magic_link: bool, providers: int, panel: bool} $state What changes the layout:
     *        magic link open, number of providers, template with a text card.
     */
    public static function render(string $tab, array $tabs, string $version, array $state): string
    {
        // Number the columns in document order: the remembered heights address them by index.
        $column = 0;
        $body = (string) preg_replace_callback(
            '/class="(portier-column|portier-methods|portier-methods__aside|portier-design__settings|portier-design__preview)"/',
            static function (array $match) use (&$column): string {
                return $match[0] . ' data-skel-col="' . $column++ . '"';
            },
            self::body($tab, $state)
        );

        return '<div class="portier-admin portier-admin--loading">'
            . self::header($tab, $tabs, $version)
            . '<div class="portier-body" aria-busy="true">'
            . '<p class="screen-reader-text" role="status">' . esc_html__('Loading settings…', 'portier') . '</p>'
            . '<div class="portier-skel-panel">' . $body . '</div>'
            . '</div></div>';
    }

    /**
     * Turns the heights the app remembered for this tab and window width (skeleton.js, same
     * key) into a style sheet. Printed before the placeholders, so it applies from their first
     * paint. Without heights for this exact width, the closest remembered width with the same
     * layout (same breakpoint range, same menu state) is used. Extra placeholders are hidden;
     * numbers only (Number()), nothing else gets into the style sheet. Printed with the tab in
     * a data-tab attribute.
     */
    public static function script(): string
    {
        // Nowdoc (no interpolation): the tab comes from the script tag's data-tab attribute.
        return <<<'JS'
(function () {
	try {
		var store = window.localStorage;
		var prefix = 'portier-skeleton:' + document.currentScript.dataset.tab + ':';
		var width = window.innerWidth;
		var folded = document.body.classList.contains('folded') ? ':folded' : '';
		// Layout breakpoints of the settings screen and of the WordPress menu (auto-fold).
		var range = function (w) { return [480, 783, 961, 1100].filter(function (b) { return w >= b; }).length; };
		var raw = store.getItem(prefix + width + folded);
		if (!raw) {
			var best = '', distance = Infinity;
			for (var i = 0; i < store.length; i++) {
				var key = store.key(i) || '';
				var parts = key.slice(prefix.length).split(':');
				var w = Number(parts[0]);
				if (key.indexOf(prefix) !== 0 || (parts[1] ? ':' + parts[1] : '') !== folded || range(w) !== range(width)) { continue; }
				if (Math.abs(w - width) < distance) { distance = Math.abs(w - width); best = key; }
			}
			raw = best ? store.getItem(best) : null;
		}
		var data = JSON.parse(raw || 'null');
		var columns = data && data.h;
		if (!Array.isArray(columns)) { return; }
		var css = '';
		columns.forEach(function (heights, i) {
			var column = '#portier-admin-root [data-skel-col="' + i + '"] > ';
			heights.forEach(function (height, j) {
				css += column + ':nth-child(' + (j + 1) + '){height:' + Number(height) + 'px}';
			});
			css += column + ':nth-child(n+' + (heights.length + 1) + '){display:none}';
		});
		// Remembered shapes replace the generic placeholders (drawn by shapesScript()).
		if (Array.isArray(data.s)) {
			window.portierSkeletonShapes = data.s;
			css += '#portier-admin-root .portier-skel-sized > :not(.portier-skel-shapes){visibility:hidden}';
		}
		var style = document.createElement('style');
		style.id = 'portier-skeleton-heights';
		style.textContent = css;
		document.head.appendChild(style);
	} catch (e) {}
})();
JS;
    }

    /**
     * Draws the remembered shapes (text lines, controls, areas) into the placeholder cards.
     * Printed right after the placeholders. Positions and sizes are numbers (Number()), the
     * kind only selects a class from a fixed list: stored data cannot add markup.
     */
    public static function shapesScript(): string
    {
        return <<<'JS'
(function () {
	try {
		var columns = window.portierSkeletonShapes;
		if (!Array.isArray(columns)) { return; }
		var kinds = { t: 'text', b: 'block', p: 'fill', o: 'frame' };
		document.querySelectorAll('#portier-admin-root [data-skel-col]').forEach(function (column) {
			var cards = columns[Number(column.getAttribute('data-skel-col'))] || [];
			Array.prototype.forEach.call(column.children, function (card, j) {
				if (!card.classList.contains('portier-skel-sized') || !Array.isArray(cards[j])) { return; }
				var layer = document.createElement('span');
				layer.className = 'portier-skel-shapes';
				cards[j].forEach(function (shape) {
					var kind = kinds[shape[0]];
					if (!kind) { return; }
					var element = document.createElement('span');
					element.className = 'portier-skel-shape portier-skel-shape--' + kind;
					element.style.left = Number(shape[1]) + 'px';
					element.style.top = Number(shape[2]) + 'px';
					element.style.width = Number(shape[3]) + 'px';
					element.style.height = Number(shape[4]) + 'px';
					element.style.borderRadius = Number(shape[5]) + 'px';
					layer.appendChild(element);
				});
				card.appendChild(layer);
			});
		});
	} catch (e) {}
})();
JS;
    }

    /**
     * @param array<string,string> $tabs
     */
    private static function header(string $tab, array $tabs, string $version): string
    {
        $links = '';
        foreach ($tabs as $slug => $label) {
            $active = AdminPage::PAGES[$slug] === $tab;
            $links .= sprintf(
                '<a class="portier-tab%1$s" href="%2$s"%3$s>%4$s</a>',
                $active ? ' is-active' : '',
                esc_url(admin_url('admin.php?page=' . $slug)),
                $active ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }

        return '<header class="portier-header"><div class="portier-header__bar">'
            . '<div class="portier-header__brand"><span class="portier-logo" aria-hidden="true"></span>'
            . '<h1 class="portier-header__title">' . esc_html__('Portier', 'portier') . '</h1>'
            . '<span class="portier-version">v' . esc_html($version) . '</span></div>'
            . '<div class="portier-header__actions" aria-hidden="true">'
            . '<span class="portier-skel portier-skel--master"></span>'
            . '<span class="portier-skel portier-skel--view"></span>'
            . '<span class="portier-skel portier-skel--save"></span>'
            . '</div></div>'
            . '<nav class="portier-tabs" aria-label="' . esc_attr__('Portier settings', 'portier') . '">' . $links . '</nav>'
            . '</header>';
    }

    /**
     * @param array{magic_link: bool, providers: int, panel: bool} $state
     */
    private static function body(string $tab, array $state): string
    {
        if ($tab === 'methods') {
            return self::methods($state);
        }
        if ($tab === 'design') {
            $cards = self::CARDS['design'][0];
            $thumb = '<span class="portier-template portier-skel-template"><span class="portier-skel portier-skel--thumb"></span>'
                . '<span class="portier-skel portier-skel--label"></span></span>';
            // First card: the template grid with eight miniature placeholders.
            $templates = '<div class="portier-card portier-skel-card portier-skel-sized"' . self::heights($cards[0]) . ' aria-hidden="true">'
                . '<span class="portier-skel portier-skel--title"></span><span class="portier-skel portier-skel--text"></span>'
                . '<div class="portier-templates">' . str_repeat($thumb, 8) . '</div></div>';
            // Templates with a brand panel have a "Texts" card after the colours.
            if ($state['panel']) {
                array_splice($cards, 3, 0, self::CARDS['design-texts'][0]);
            }
            return '<div class="portier-design"><div class="portier-design__settings">'
                . $templates . implode('', array_map([self::class, 'card'], array_slice($cards, 1))) . '</div>'
                . '<div class="portier-design__preview"><div class="portier-preview portier-skel-sized"'
                . self::heights(self::PREVIEW) . ' aria-hidden="true">'
                . '<div class="portier-preview__toolbar"><span class="portier-skel portier-skel--segmented"></span>'
                . '<span class="portier-skel-select"><span class="portier-skel portier-skel--label"></span>'
                . '<span class="portier-skel portier-skel--select"></span></span></div>'
                . '<div class="portier-preview__stage"><span class="portier-skel portier-skel--phone"></span></div>'
                . '</div></div></div>';
        }
        $columns = '';
        foreach (self::CARDS[$tab] ?? self::CARDS['advanced'] as $cards) {
            $columns .= self::column('portier-column', $cards);
        }
        return '<div class="portier-columns">' . $columns . '</div>';
    }

    /**
     * @param list<array{int,int}> $cards
     */
    private static function column(string $class, array $cards): string
    {
        return '<div class="' . $class . '">' . implode('', array_map([self::class, 'card'], $cards)) . '</div>';
    }

    /**
     * Card placeholder of the given height: title, description and as many field shapes as fit.
     *
     * @param array{int,int} $height [phone, from 783 px]
     */
    private static function card(array $height): string
    {
        $rows = max(1, intdiv(max($height) - 110, 56));
        return '<div class="portier-card portier-skel-card portier-skel-sized"' . self::heights($height) . ' aria-hidden="true">'
            . '<span class="portier-skel portier-skel--title"></span>'
            . '<span class="portier-skel portier-skel--text"></span>'
            . str_repeat('<span class="portier-skel portier-skel--field"></span>', $rows)
            . '</div>';
    }

    /**
     * @param array{magic_link: bool, providers: int, panel: bool} $state
     */
    private static function methods(array $state): string
    {
        $rows = self::method(self::ROWS['password'])
            . self::method(self::ROWS[$state['magic_link'] ? 'magic-open' : 'magic'])
            // "Below the login form": heading, hint and the sortable passkey and provider rows.
            . '<div class="portier-methods__sortable portier-skel-sized" aria-hidden="true">'
            . '<span class="portier-skel portier-skel--title"></span><span class="portier-skel portier-skel--text"></span>'
            . str_repeat(self::method(self::ROWS['provider']), $state['providers'] + 1)
            . '</div>';

        // The intro is static text: the real heading, so it has the real size.
        return '<div class="portier-methods-layout"><div class="portier-methods">'
            . '<div class="portier-methods__intro"><h2 class="portier-section-title">' . esc_html__('Login methods', 'portier') . '</h2>'
            . '<p class="portier-card__description">'
            . esc_html__('Choose how people can log in. Two-factor authentication applies to every method.', 'portier')
            . '</p></div>'
            . $rows
            . '</div>'
            . self::column('portier-methods__aside', self::CARDS['methods-aside'][0])
            . '</div>';
    }

    /**
     * @param array{int,int} $height [phone, from 783 px]
     */
    private static function method(array $height): string
    {
        return '<div class="portier-method portier-skel-sized"' . self::heights($height) . ' aria-hidden="true">'
            . '<div class="portier-method__row">'
            . '<span class="portier-skel portier-skel--icon"></span>'
            . '<span class="portier-skel-method__text"><span class="portier-skel portier-skel--title"></span>'
            . '<span class="portier-skel portier-skel--text"></span></span>'
            . '<span class="portier-skel portier-skel--switch"></span></div></div>';
    }

    /**
     * Heights as custom properties (the CSS picks the one for the screen width).
     *
     * @param array{int,int} $height [phone, from 783 px]
     */
    private static function heights(array $height): string
    {
        return sprintf(' style="--skel-hm:%dpx;--skel-h:%dpx"', $height[0], $height[1]);
    }
}
