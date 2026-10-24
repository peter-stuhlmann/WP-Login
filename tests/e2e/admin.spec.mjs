/**
 * Settings screen in wp-admin (design: Figma "06 Admin").
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { ADMIN, site } from './support/site.mjs';
import { logIn } from './support/login.mjs';

test.beforeEach( async ( { page } ) => {
	await logIn( page, ADMIN );
	await page.waitForURL( /wp-admin/ );
} );

/**
 * Opens a settings tab by its admin page slug.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          slug
 */
async function openTab( page, slug ) {
	await page.goto( `wp-admin/admin.php?page=${ slug }` );
	await expect( page.getByRole( 'tablist' ) ).toBeVisible();
}

/**
 * Replaces the content of the CSS editor (CodeMirror) like typing would.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          css
 */
async function setCss( page, css ) {
	await page.evaluate( ( value ) => {
		const cm = document.querySelector(
			'.portier-css-editor .CodeMirror'
		).CodeMirror;
		cm.setValue( '' );
		cm.replaceRange( value, { line: 0, ch: 0 } );
	}, css );
}

test( 'the layout is there before the app has loaded', async ( { page } ) => {
	// Hold the app back: the server-rendered placeholders stay visible.
	await page.route( /build\/admin\.js/, ( route ) => route.abort() );
	await page.goto( 'wp-admin/admin.php?page=portier-security' );
	const root = page.locator( '#portier-admin-root' );
	await expect(
		root.locator( '.portier-columns .portier-card' )
	).toHaveCount( 5 );
	await expect( root.getByRole( 'status' ) ).toHaveText(
		/Einstellungen werden geladen|Loading settings/
	);
	// The tabs are real links to the tab pages.
	await expect(
		root.getByRole( 'link', { name: /Sicherheit|Security/ } )
	).toHaveAttribute( 'aria-current', 'page' );
	const results = await new AxeBuilder( { page } )
		.include( '#portier-admin-root' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect(
		results.violations,
		JSON.stringify( results.violations, null, 2 )
	).toEqual( [] );

	// With the app, the same place holds the real screen.
	await page.unroute( /build\/admin\.js/ );
	await page.reload();
	await expect( page.getByRole( 'tablist' ) ).toBeVisible();
	await expect( root.locator( '.portier-skel' ) ).toHaveCount( 0 );
} );

test( 'on the next visit the placeholders have the size of the real cards', async ( {
	page,
} ) => {
	// Position and size of every card in every column, rounded to whole pixels.
	const boxes = () =>
		page.evaluate( () =>
			[
				...document.querySelectorAll(
					'#portier-admin-root :is(.portier-column, .portier-methods, .portier-methods__aside, .portier-design__settings, .portier-design__preview)'
				),
			].map( ( column ) =>
				[ ...column.children ]
					.filter( ( child ) => child.offsetParent !== null )
					.map( ( child ) => {
						const box = child.getBoundingClientRect();
						return [
							Math.round( box.x ),
							Math.round( box.y ),
							Math.round( box.width ),
							Math.round( box.height ),
						];
					} )
			)
		);
	for ( const slug of [ 'portier', 'portier-security', 'portier-design' ] ) {
		await openTab( page, slug );
		await page.waitForTimeout( 1200 ); // The app remembers the layout after 800 ms.
		const real = await boxes();

		await page.route( /build\/admin\.js/, ( route ) => route.abort() );
		await page.reload();
		await expect( page.locator( '.portier-skel' ).first() ).toBeVisible();
		expect( await boxes(), slug ).toEqual( real );
		// Inside the cards: the remembered shapes of the real content.
		await expect(
			page.locator( '.portier-skel-shape--text' ).first()
		).toBeVisible();
		await page.unroute( /build\/admin\.js/ );
	}
} );

test( 'settings screen has no WCAG violations', async ( { page } ) => {
	for ( const tab of [
		'portier',
		'portier-security',
		'portier-design',
		'portier-registration',
		'portier-advanced',
	] ) {
		await openTab( page, tab );
		const results = await new AxeBuilder( { page } )
			.include( '#portier-admin-root' )
			.withTags( [
				'wcag2a',
				'wcag2aa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
			] )
			.analyze();
		expect(
			results.violations,
			JSON.stringify( results.violations, null, 2 )
		).toEqual( [] );
	}
} );

test( 'tabs work with arrow keys and keep the URL in sync', async ( {
	page,
} ) => {
	await openTab( page, 'portier' );
	const methods = page.getByRole( 'tab', { selected: true } );
	await expect( methods ).toHaveId( 'portier-tab-methods' );
	await methods.focus();
	await page.keyboard.press( 'ArrowRight' );
	const security = page.getByRole( 'tab', { selected: true } );
	await expect( security ).toBeFocused();
	await expect( security ).toHaveId( 'portier-tab-security' );
	await expect( page ).toHaveURL( /page=portier-security/ );
	await expect( page.getByRole( 'tabpanel' ) ).toHaveAttribute(
		'aria-labelledby',
		'portier-tab-security'
	);
	// Wraps around at the start.
	await page.keyboard.press( 'ArrowLeft' );
	await page.keyboard.press( 'ArrowLeft' );
	await expect( page.getByRole( 'tab', { selected: true } ) ).toHaveId(
		'portier-tab-advanced'
	);
} );

test( 'switches are real switches and changes are counted', async ( {
	page,
	isMobile,
} ) => {
	await openTab( page, 'portier-security' );
	const xmlrpc = page.getByRole( 'switch', { name: 'XML-RPC' } );
	const before = await xmlrpc.getAttribute( 'aria-checked' );
	await xmlrpc.click();
	await expect( xmlrpc ).toHaveAttribute(
		'aria-checked',
		before === 'true' ? 'false' : 'true'
	);

	const save = isMobile
		? page.locator( '.portier-savebar__save' )
		: page.locator( '.portier-header__save' );
	await expect( save ).toBeEnabled();
	if ( isMobile ) {
		await expect( page.locator( '.portier-savebar__text' ) ).toContainText(
			'1'
		);
	}
	// Undo: nothing left to save.
	await xmlrpc.click();
	await expect( save ).toBeDisabled();
} );

test( 'switching the audit log off asks first', async ( { page } ) => {
	await openTab( page, 'portier-advanced' );
	const log = page.getByRole( 'switch', {
		name: /Record actions|Aktionen protokollieren/,
	} );
	await expect( log ).toHaveAttribute( 'aria-checked', 'true' );
	const dialog = page.getByRole( 'alertdialog', {
		name: /Switch the audit log off\?|Protokoll ausschalten\?/,
	} );

	// Esc keeps it on, and focus returns to the switch.
	await log.click();
	await expect( dialog ).toBeVisible();
	await expect( dialog ).toHaveAccessibleDescription(
		/no longer recorded|nicht mehr festgehalten/
	);
	await expect(
		dialog.getByRole( 'button', {
			name: /Keep recording|Weiter protokollieren/,
		} )
	).toBeFocused();
	const results = await new AxeBuilder( { page } )
		.include( '.portier-dialog' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect(
		results.violations,
		JSON.stringify( results.violations, null, 2 )
	).toEqual( [] );
	await page.keyboard.press( 'Escape' );
	await expect( dialog ).toBeHidden();
	await expect( log ).toHaveAttribute( 'aria-checked', 'true' );
	await expect( log ).toBeFocused();

	// "Keep recording" too.
	await log.click();
	await dialog
		.getByRole( 'button', { name: /Keep recording|Weiter protokollieren/ } )
		.click();
	await expect( dialog ).toBeHidden();
	await expect( log ).toHaveAttribute( 'aria-checked', 'true' );

	// Confirming switches it off (not saved here); switching on needs no question.
	await log.click();
	await dialog
		.getByRole( 'button', { name: /Switch off|Ausschalten/ } )
		.click();
	await expect( dialog ).toBeHidden();
	await expect( log ).toHaveAttribute( 'aria-checked', 'false' );
	await log.click();
	await expect( dialog ).toBeHidden();
	await expect( log ).toHaveAttribute( 'aria-checked', 'true' );
} );

test( 'saving a new login address takes effect immediately', async ( {
	page,
	isMobile,
} ) => {
	await openTab( page, 'portier-security' );
	const url = page.locator( '.portier-input input' ).first();
	const save = isMobile
		? page.locator( '.portier-savebar__save' )
		: page.locator( '.portier-header__save' );
	// The button is also disabled *while* saving, so wait for the server's answer instead.
	const saveAndWait = () =>
		Promise.all( [
			page.waitForResponse(
				( response ) =>
					response.url().includes( 'portier' ) &&
					response.url().includes( 'settings' ) &&
					response.request().method() === 'POST'
			),
			save.click(),
		] );

	await url.fill( 'e2e-anmelden' );
	const [ saved ] = await saveAndWait();
	expect( saved.status() ).toBe( 200 );
	await expect( page.getByRole( 'status' ).last() ).toContainText(
		/gespeichert|saved/i
	);
	expect( ( await page.request.get( 'e2e-anmelden/' ) ).status() ).toBe(
		200
	);

	await url.fill( 'login' );
	const [ restored ] = await saveAndWait();
	expect( restored.status() ).toBe( 200 );
	expect( ( await page.request.get( 'login/' ) ).status() ).toBe( 200 );
} );

test( 'invalid addresses are explained, not saved', async ( { page } ) => {
	await openTab( page, 'portier-security' );
	const url = page.locator( '.portier-input input' ).first();
	await url.fill( 'wp-admin' );
	await expect( url ).toHaveAttribute( 'aria-invalid', 'true' );
	await expect( page.getByRole( 'alert' ).first() ).toBeVisible();
} );

test( 'the magic link duration appears only when the method is on', async ( {
	page,
} ) => {
	await openTab( page, 'portier' );
	const magic = page.getByRole( 'switch', { name: /Magic link/i } );
	const minutes = page.getByLabel( /Link valid for|Link gültig für/i );
	if ( ( await magic.getAttribute( 'aria-checked' ) ) === 'true' ) {
		await magic.click();
	}
	await expect( minutes ).toHaveCount( 0 );
	await magic.click();
	await expect( minutes ).toBeVisible();
} );

test( 'required two-factor asks for the roles', async ( { page } ) => {
	await openTab( page, 'portier-security' );
	await page
		.getByRole( 'radio', {
			name: /Required for roles|Pflicht für Rollen/i,
		} )
		.check();
	const roles = page.getByRole( 'group', {
		name: /^(Required for|Pflicht für)$/,
	} );
	await expect( roles ).toBeVisible();
	await expect(
		roles.getByRole( 'checkbox', { name: /Administrator/i } )
	).toBeVisible();
	await page.getByRole( 'radio', { name: /^Optional$/ } ).check();
	await expect( roles ).toHaveCount( 0 );
} );

test( 'the layout fits small screens', async ( { page } ) => {
	for ( const tab of [ 'portier', 'portier-security' ] ) {
		await openTab( page, tab );
		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - window.innerWidth
		);
		expect( overflow ).toBeLessThanOrEqual( 0 );
	}
} );

test( 'social login providers are configured in place', async ( { page } ) => {
	await openTab( page, 'portier' );
	const configure = page.getByRole( 'button', {
		name: /^(Configure|Konfigurieren) Google$/,
	} );
	await expect( configure ).toHaveAttribute( 'aria-expanded', 'false' );
	await configure.click();
	await expect(
		page.getByRole( 'button', { name: /^(Collapse|Einklappen) Google$/ } )
	).toHaveAttribute( 'aria-expanded', 'true' );

	// The browser must never autofill the WordPress password into the secret.
	const secret = page.getByLabel( /Client secret|Client-Secret/ ).first();
	await expect( secret ).toHaveAttribute( 'autocomplete', 'new-password' );
	await expect( secret ).toHaveAttribute( 'type', 'password' );
	const redirect = page
		.getByLabel( /Redirect URI|Weiterleitungs-URI/ )
		.first();
	await expect( redirect ).toHaveValue( /\/oauth\/google\/$/ );
	await expect( redirect ).toHaveAttribute( 'readonly', '' );

	const results = await new AxeBuilder( { page } )
		.include( '#portier-admin-root' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect(
		results.violations,
		JSON.stringify( results.violations, null, 2 )
	).toEqual( [] );
} );

test( 'enabling a provider without credentials is explained', async ( {
	page,
	isMobile,
} ) => {
	await openTab( page, 'portier' );
	const toggle = page.getByRole( 'switch', { name: /^Discord/ } );
	if ( ( await toggle.getAttribute( 'aria-checked' ) ) === 'true' ) {
		test.skip( true, 'Discord is configured on this site.' );
	}
	await toggle.click();
	// The settings open right away and show what is missing.
	await expect(
		page.getByLabel( /Client ID|Client-ID/ ).last()
	).toBeVisible();
	const save = isMobile
		? page.locator( '.portier-savebar__save' )
		: page.locator( '.portier-header__save' );
	const [ response ] = await Promise.all( [
		page.waitForResponse(
			( r ) =>
				r.url().includes( 'settings' ) &&
				r.request().method() === 'POST'
		),
		save.click(),
	] );
	expect( response.status() ).toBe( 400 );
	await expect( page.getByRole( 'alert' ).first() ).toBeVisible();
} );

test( 'the design tab previews templates live and saves them', async ( {
	page,
	isMobile,
} ) => {
	await openTab( page, 'portier-design' );
	const preview = page.frameLocator( '.portier-preview iframe' );
	await expect( preview.locator( 'html' ) ).toHaveAttribute(
		'data-portier-template',
		'aurora'
	);

	// Arrow keys move through the templates (native radio group).
	await page.locator( '#portier-template-aurora' ).focus();
	await page.keyboard.press( 'ArrowRight' );
	await expect( page.locator( '#portier-template-pure' ) ).toBeChecked();

	await page.locator( 'label[for="portier-template-journal"]' ).click();
	await expect( preview.locator( 'html' ) ).toHaveAttribute(
		'data-portier-template',
		'journal'
	);
	// Not saved yet: the real login page is unchanged.
	const live = await page.request.get( 'login/' );
	expect( await live.text() ).toContain( 'data-portier-template="aurora"' );

	const save = isMobile
		? page.locator( '.portier-savebar__save' )
		: page.locator( '.portier-header__save' );
	const saveAndWait = () =>
		Promise.all( [
			page.waitForResponse(
				( r ) =>
					r.url().includes( 'settings' ) &&
					r.request().method() === 'POST'
			),
			save.click(),
		] );
	await saveAndWait();
	expect( await ( await page.request.get( 'login/' ) ).text() ).toContain(
		'data-portier-template="journal"'
	);

	// Back to the default for the other tests.
	await page.locator( 'label[for="portier-template-aurora"]' ).click();
	await saveAndWait();
} );

test( 'the phone preview shows the device and gives the page its safe areas', async ( {
	page,
} ) => {
	await openTab( page, 'portier-design' );
	const preview = page.frameLocator( '.portier-preview iframe' );
	const phone = page.locator( '.portier-phone' );
	// The status bar shows the current time (hours without leading zero).
	await expect( phone.locator( '.portier-phone__time' ) ).toHaveText(
		/^(\d|1\d|2[0-3]):[0-5]\d$/
	);
	await expect( phone ).toHaveAttribute( 'aria-hidden', 'true' );
	// Status bar and home indicator are insets of the page, like on a real phone.
	await expect( preview.locator( '.portier-header' ) ).toHaveCSS(
		'padding-top',
		'60px'
	);

	// Dark top area (Midnight): white status bar.
	await page.locator( 'label[for="portier-template-midnight"]' ).click();
	await expect( preview.locator( 'html' ) ).toHaveAttribute(
		'data-portier-template',
		'midnight'
	);
	await expect( phone.locator( '.portier-phone__status' ) ).toHaveClass(
		/is-light/
	);

	// Other devices have no phone chrome and no insets.
	await page
		.getByRole( 'radio', { name: 'Desktop' } )
		.check( { force: true } );
	await expect( phone ).toHaveCount( 0 );
	await expect( preview.locator( '.portier-header' ) ).not.toHaveCSS(
		'padding-top',
		'60px'
	);

	// "Own design" leads to the colour settings.
	await page
		.getByRole( 'button', { name: /Eigenes Design|Own design/ } )
		.click();
	await expect( page.getByLabel( /^(Primär|Primary)$/ ) ).toBeFocused();
} );

test( 'the preview scrolls like a phone: drag, wheel and keys, no scrollbar', async ( {
	page,
	isMobile,
} ) => {
	test.skip( isMobile, 'Drag and wheel are tested with a mouse.' );
	await openTab( page, 'portier-design' );
	const preview = page.frameLocator( '.portier-preview iframe' );
	await expect( preview.locator( 'html' ) ).toHaveAttribute(
		'data-portier-template',
		'aurora'
	);
	const scrollTop = () =>
		page.evaluate(
			() =>
				document.querySelector( '.portier-preview iframe' )
					.contentDocument.scrollingElement.scrollTop
		);
	// No scrollbar on the phone.
	await expect( preview.locator( 'html' ) ).toHaveCSS(
		'scrollbar-width',
		'none'
	);

	// Drag up: the page follows the pointer.
	const screen = page.locator( '.portier-preview__screen' );
	const box = await screen.boundingBox();
	const x = box.x + box.width / 2;
	await page.mouse.move( x, box.y + box.height - 60 );
	await page.mouse.down();
	await page.mouse.move( x, box.y + box.height - 160, { steps: 10 } );
	await page.mouse.up();
	await expect.poll( scrollTop ).toBeGreaterThan( 50 );

	// Keys: Home goes back to the top, End to the bottom.
	await screen.focus();
	await page.keyboard.press( 'Home' );
	await expect.poll( scrollTop ).toBe( 0 );
	await page.keyboard.press( 'End' );
	await expect.poll( scrollTop ).toBeGreaterThan( 50 );

	// Wheel up scrolls the preview, not the admin page.
	const pageY = await page.evaluate( () => window.scrollY );
	await page.mouse.move( x, box.y + box.height / 2 );
	await page.mouse.wheel( 0, -2000 );
	await expect.poll( scrollTop ).toBe( 0 );
	expect( await page.evaluate( () => window.scrollY ) ).toBe( pageY );
} );

test( 'colours are checked for contrast live and custom CSS shows in the preview', async ( {
	page,
} ) => {
	await openTab( page, 'portier-design' );
	const status = page.locator( '.portier-design .portier-notice' ).first();
	await expect( status ).toHaveClass( /portier-notice--success/ );

	// A pale accent fails WCAG for links and the focus ring.
	await page.getByLabel( /^(Akzent|Accent)$/ ).fill( '#ffe14d' );
	await expect( status ).toHaveClass( /portier-notice--warning/ );
	await expect(
		page.locator( '.portier-contrast__row.is-fail' )
	).not.toHaveCount( 0 );
	await page.getByLabel( /^(Akzent|Accent)$/ ).fill( '' );
	await expect( status ).toHaveClass( /portier-notice--success/ );

	// Custom CSS reaches the preview while typing (no saving, no reload).
	const editor = page.locator( '.portier-css-editor .CodeMirror' );
	if ( await editor.count() ) {
		await setCss( page, '.portier-title { letter-spacing: 0.3em; }' );
		// The rule takes effect in the preview: 0.3em of the 28 px title.
		const preview = page.frameLocator( '.portier-preview iframe' );
		await expect( preview.locator( '.portier-title' ) ).toHaveCSS(
			'letter-spacing',
			'8.4px'
		);
	}
} );

test( 'the CSS editor highlights, warns about invalid CSS and formats valid CSS', async ( {
	page,
} ) => {
	await openTab( page, 'portier-design' );
	const box = page.locator( '.portier-css-editor' );
	await expect( box.locator( '.CodeMirror' ) ).toBeVisible();
	// The editor's input is named by the visible label.
	await expect(
		box.locator( '.CodeMirror [aria-labelledby]' )
	).toHaveAccessibleName( 'CSS' );

	await setCss(
		page,
		`body {
	background: red

.portier-title { color: red }`
	);
	const warning = box.locator( '.portier-css-editor__warning' );
	await expect( warning ).toBeVisible();
	await expect( warning ).toContainText( /Zeile 4|Line 4/ );
	await expect( box.locator( '.CodeMirror-lint-marker-error' ) ).toHaveCount(
		1
	);
	await expect(
		box.getByRole( 'button', { name: /Formatieren|Format/ } )
	).toBeDisabled();

	await setCss( page, 'body{background:red}.a,.b{color:#333}' );
	await expect( warning ).toBeHidden();
	await box.getByRole( 'button', { name: /Formatieren|Format/ } ).click();
	await expect.poll( () =>
		page.evaluate( () =>
			document
				.querySelector( '.portier-css-editor .CodeMirror' )
				.CodeMirror.getValue()
		)
	).toBe( `body {
	background: red
}

.a,
.b {
	color: #333
}` );
	// Nothing saved in this test: leave without the unsaved-changes warning.
	await setCss( page, '' );
} );

test( 'the background image card controls the preview', async ( { page } ) => {
	site( 'photo', '50' );
	try {
		await openTab( page, 'portier-design' );
		const preview = page.frameLocator( '.portier-preview iframe' );
		await expect( preview.locator( 'html' ) ).toHaveAttribute(
			'data-portier-photo',
			'all'
		);
		await page
			.getByLabel( /Transparenz des Overlays|Overlay transparency/ )
			.fill( '80' );
		await expect( preview.locator( '.portier-backdrop' ) ).toHaveCSS(
			'opacity',
			'0.2'
		);
		await page
			.getByLabel( /Fokuspunkt horizontal|Focus point horizontal/ )
			.fill( '10' );
		await expect( preview.locator( '.portier-photo' ) ).toHaveCSS(
			'background-position',
			/^10% /
		);

		// "Remove" leaves the template alone; "Choose image" opens the media library.
		await page.getByRole( 'button', { name: /Entfernen|Remove/ } ).click();
		await page
			.getByRole( 'button', { name: /Bild wählen|Choose image/ } )
			.click();
		await expect( page.locator( '.media-modal' ) ).toBeVisible();
	} finally {
		site( 'photo-off' );
	}
} );
