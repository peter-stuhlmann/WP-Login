/**
 * User journeys on the login screens.
 */
import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { LOGIN_PATH, USER, site } from './support/site.mjs';

const loginField = ( page ) => page.locator( '#portier-log' );
const passwordField = ( page ) => page.locator( '#portier-pwd' );

test( 'wp-login.php redirects to the custom address', async ( { page } ) => {
	await page.goto( 'wp-login.php' );
	await expect( page ).toHaveURL(
		new RegExp( `${ LOGIN_PATH.replace( /\//g, '\\/' ) }$` )
	);
	await expect( page.locator( 'h1' ) ).toBeVisible();
} );

test( 'wp-admin sends guests to the login with a redirect back', async ( {
	page,
} ) => {
	await page.goto( 'wp-admin/options-general.php' );
	await expect( page ).toHaveURL( /redirect_to=/ );
} );

test( 'registration shows a friendly closed screen when disabled', async ( {
	page,
} ) => {
	const response = await page.goto( `${ LOGIN_PATH }?action=register` );
	if ( response.status() === 403 ) {
		await expect( page.locator( 'h1' ) ).toBeVisible();
		await expect(
			page.locator( 'a.portier-button--primary' )
		).toBeVisible();
	}
} );

test.describe( 'login and registration', () => {
	test.beforeAll( () => site( 'registration', 'on' ) );
	test.afterAll( () => site( 'registration', 'off' ) );

	test( 'switch with a view transition: the card grows, shared parts move', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		// Record the transition: the shared parts must form pairs (old and new image).
		await page.evaluate( () => {
			window.portierTransitions = [];
			const start = document.startViewTransition.bind( document );
			document.startViewTransition = ( update ) => {
				const transition = start( update );
				transition.ready.then(
					() =>
						window.portierTransitions.push(
							document
								.getAnimations()
								.map(
									( animation ) =>
										animation.effect.pseudoElement
								)
						),
					( error ) =>
						window.portierTransitions.push( String( error ) )
				);
				return transition;
			};
		} );

		await page
			.getByRole( 'link', { name: /Konto erstellen|Create an account/ } )
			.click();
		await expect( page ).toHaveURL( /action=register/ );
		await expect( page.locator( '#portier-title' ) ).toBeFocused();
		const [ pseudo ] = await page.evaluate(
			() => window.portierTransitions
		);
		expect( Array.isArray( pseudo ), String( pseudo ) ).toBe( true );
		// The card only changes its size (it moves as a group) …
		expect( pseudo ).toContain( '::view-transition-group(portier-card)' );
		// … the shared parts move and cross-fade between old and new.
		for ( const part of [
			'portier-title',
			'portier-identity',
			'portier-submit',
			'portier-switch',
		] ) {
			expect( pseudo ).toContain( `::view-transition-group(${ part })` );
			expect( pseudo ).toContain( `::view-transition-old(${ part })` );
			expect( pseudo ).toContain( `::view-transition-new(${ part })` );
		}
		// Names are removed afterwards (no collisions with the next change).
		await expect( page.locator( '#portier-title' ) ).toHaveCSS(
			'view-transition-name',
			'none'
		);

		// And back.
		await page.getByRole( 'link', { name: /^(Anmelden|Log in)$/ } ).click();
		await expect( page ).not.toHaveURL( /action=register/ );
		await expect(
			page.locator( 'input[autocomplete^="username"]' )
		).toBeVisible();
	} );

	test( 'the chosen language stays: in-place changes, reload and the switcher match', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		const switcher = page.locator( '.portier-lang' );
		test.skip(
			( await switcher.count() ) === 0,
			'Only one language installed.'
		);
		const choose = async ( hreflang ) => {
			await switcher.locator( 'summary' ).click();
			await switcher.locator( `a[hreflang="${ hreflang }"]` ).click();
		};
		const expectLanguage = async ( lang, heading ) => {
			await expect( page.locator( 'html' ) ).toHaveAttribute(
				'lang',
				new RegExp( `^${ lang }` )
			);
			await expect( page.locator( 'h1' ) ).toHaveText( heading );
			await expect(
				switcher.locator( 'summary span[aria-hidden="true"]' )
			).toHaveText( lang.slice( 0, 2 ).toUpperCase() );
		};

		// An older choice (English), then back to the site's default language (German).
		await choose( 'en-US' );
		await expectLanguage( 'en', 'Welcome back' );
		await choose( 'de-DE' );
		await expectLanguage( 'de', 'Willkommen zurück' );
		// The choice lives in the cookie, not in the address.
		await expect( page ).not.toHaveURL( /wp_lang=/ );

		// In-place change to the registration: still German, switcher included.
		await page.getByRole( 'link', { name: 'Konto erstellen' } ).click();
		await expect( page ).toHaveURL( /action=register/ );
		await expectLanguage( 'de', 'Konto erstellen' );
		await page.reload();
		await expectLanguage( 'de', 'Konto erstellen' );

		// The switcher now leads to the registration in the other language.
		await choose( 'en-US' );
		await expect( page ).toHaveURL( /action=register/ );
		await expectLanguage( 'en', 'Create your account' );
		await page.getByRole( 'link', { name: 'Log in' } ).click();
		await expectLanguage( 'en', 'Welcome back' );
		await page.reload();
		await expectLanguage( 'en', 'Welcome back' );
	} );
} );

test( 'the language can be switched without JavaScript tricks', async ( {
	page,
} ) => {
	await page.goto( LOGIN_PATH );
	const switcher = page.locator( '.portier-lang' );
	test.skip(
		( await switcher.count() ) === 0,
		'Only one language installed.'
	);
	await switcher.locator( 'summary' ).click();
	await switcher.locator( 'a[hreflang="en-US"]' ).click();
	await expect( page.locator( 'html' ) ).toHaveAttribute( 'lang', /en/ );
	await expect( page.locator( 'h1' ) ).toHaveText( 'Welcome back' );
} );

test.describe( 'one step (default)', () => {
	test.beforeAll( () => site( 'steps', 'one' ) );

	test( 'a successful login lands in the dashboard', async ( { page } ) => {
		await page.goto( LOGIN_PATH );
		await loginField( page ).fill( USER.login );
		await passwordField( page ).fill( USER.password );
		await page.locator( '[data-portier-submit]' ).click();
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'a failed login shows a neutral error and focuses the summary', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		await loginField( page ).fill( USER.login );
		await passwordField( page ).fill( 'definitely-wrong' );
		await passwordField( page ).press( 'Enter' );

		const summary = page.locator( '#portier-errors' );
		await expect( summary ).toBeFocused();
		await expect( summary ).toHaveAttribute( 'role', 'alert' );
		await expect( loginField( page ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		await expect( loginField( page ) ).toHaveValue( USER.login );
	} );

	test( 'the whole login works with the keyboard only', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '.portier-skip-link' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await page.keyboard.press( 'Tab' );
		await expect( loginField( page ) ).toBeFocused();
		await page.keyboard.type( USER.login );
		await page.keyboard.press( 'Tab' ); // "Forgot password?" link
		await page.keyboard.press( 'Tab' );
		await expect( passwordField( page ) ).toBeFocused();
		await page.keyboard.type( USER.password );
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'show password toggle is a real, labelled button', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		const toggle = page.locator( '[data-portier-reveal]' );
		await passwordField( page ).fill( 'secret' );
		await toggle.click();
		await expect( passwordField( page ) ).toHaveAttribute( 'type', 'text' );
		await expect( toggle ).toHaveAttribute( 'aria-pressed', 'true' );
		await toggle.click();
		await expect( passwordField( page ) ).toHaveAttribute(
			'type',
			'password'
		);
	} );

	test( 'forgot password loads in place, updates the URL and supports the back button', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		await page.locator( '.portier-field__aside' ).click();
		await expect( page ).toHaveURL( /action=lostpassword/ );
		await expect( page.locator( '#portier-title' ) ).toBeFocused();

		await page
			.locator( '#portier-user_login' )
			.fill( 'nobody@example.org' );
		await page.locator( '[data-portier-submit]' ).click();
		await expect( page ).toHaveURL( /checkemail=confirm/ );
		await expect( page.locator( '[role=status]' ) ).toBeVisible();

		await page.goBack();
		await expect( page ).toHaveURL( /action=lostpassword/ );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'login still works', async ( { page } ) => {
			await page.goto( LOGIN_PATH );
			await loginField( page ).fill( USER.login );
			await passwordField( page ).fill( USER.password );
			await page.locator( '[data-portier-submit]' ).click();
			await expect( page ).toHaveURL( /wp-admin/ );
		} );

		test( 'errors are shown after a full page load', async ( { page } ) => {
			await page.goto( LOGIN_PATH );
			await loginField( page ).fill( 'nobody@example.org' );
			await passwordField( page ).fill( 'wrong' );
			await page.locator( '[data-portier-submit]' ).click();
			await expect( page.locator( '#portier-errors' ) ).toBeVisible();
		} );
	} );
} );

test.describe( 'two steps (Figma)', () => {
	test.beforeAll( () => site( 'steps', 'two' ) );
	test.afterAll( () => site( 'steps', 'one' ) );
	const continueButton = ( page ) => page.locator( '[data-portier-submit]' );

	test( 'continue moves to the password and puts the cursor there', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		await expect( passwordField( page ) ).toHaveCount( 0 );
		await loginField( page ).fill( USER.login );
		await continueButton( page ).click();
		await expect( page ).toHaveURL( /step=password/ );
		await expect( page ).not.toHaveURL( new RegExp( USER.login ) );
		await expect( passwordField( page ) ).toBeFocused();
		await expect( page.locator( '.portier-account' ) ).toContainText(
			USER.login
		);

		await passwordField( page ).fill( USER.password );
		await passwordField( page ).press( 'Enter' );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'a wrong password stays on the password step', async ( { page } ) => {
		await page.goto( LOGIN_PATH );
		await loginField( page ).fill( USER.login );
		await continueButton( page ).click();
		await passwordField( page ).fill( 'definitely-wrong' );
		await passwordField( page ).press( 'Enter' );
		await expect( page.locator( '#portier-errors' ) ).toBeFocused();
		await expect( page.locator( '.portier-account' ) ).toContainText(
			USER.login
		);
		const results = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa' ] )
			.analyze();
		expect(
			results.violations,
			JSON.stringify( results.violations, null, 2 )
		).toEqual( [] );
	} );

	test( 'change goes back with the login filled in', async ( { page } ) => {
		await page.goto( LOGIN_PATH );
		await loginField( page ).fill( USER.login );
		await continueButton( page ).click();
		await page.locator( '.portier-account__change' ).click();
		await expect( loginField( page ) ).toHaveValue( USER.login );
		await expect( page.locator( '#portier-title' ) ).toBeFocused();
	} );

	test( 'the whole login works with the keyboard only', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '.portier-skip-link' ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await page.keyboard.press( 'Tab' );
		await expect( loginField( page ) ).toBeFocused();
		await page.keyboard.type( USER.login );
		await page.keyboard.press( 'Enter' );
		await expect( passwordField( page ) ).toBeFocused();
		await page.keyboard.type( USER.password );
		await page.keyboard.press( 'Tab' ); // "show password"
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '#portier-rememberme' ) ).toBeFocused();
		await page.keyboard.press( 'Space' );
		await expect( page.locator( '#portier-rememberme' ) ).toBeChecked();
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'the checkbox looks like the Figma design', async ( { page } ) => {
		await page.goto( LOGIN_PATH );
		await loginField( page ).fill( USER.login );
		await continueButton( page ).click();
		const mark = page.locator( '.portier-check__mark' ).first();
		const box = await mark.boundingBox();
		expect( Math.round( box.width ) ).toBe( 22 );
		expect( Math.round( box.height ) ).toBe( 22 );
		const target = await page
			.locator( '#portier-rememberme' )
			.boundingBox();
		expect( target.width ).toBeGreaterThanOrEqual( 24 ); // WCAG 2.5.8

		const background = () =>
			mark.evaluate(
				( el ) => window.getComputedStyle( el ).backgroundColor
			);
		const unchecked = await background();
		await page.locator( '.portier-check__label' ).first().click();
		await expect( page.locator( '#portier-rememberme' ) ).toBeChecked();
		// The fill fades in (150 ms): wait for the accent colour.
		await expect.poll( background ).not.toBe( unchecked );
	} );

	test.describe( 'without JavaScript', () => {
		test.use( { javaScriptEnabled: false } );

		test( 'both steps work as plain forms', async ( { page } ) => {
			await page.goto( LOGIN_PATH );
			await loginField( page ).fill( USER.login );
			await continueButton( page ).click();
			await expect( page ).toHaveURL( /step=password/ );
			await passwordField( page ).fill( USER.password );
			await continueButton( page ).click();
			await expect( page ).toHaveURL( /wp-admin/ );
		} );
	} );
} );
