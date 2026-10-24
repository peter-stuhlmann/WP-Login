/**
 * Second factor and magic link journeys in a real browser.
 *
 * The site is prepared by support/wp-cli.php: "portier-e2e-2fa" has 2FA with a known secret,
 * "portier-e2e-setup" is a contributor (2FA required for that role), magic links are on.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import {
	LOGIN_PATH,
	SETUP_USER,
	TWO_FACTOR_USER,
	USER,
	site,
} from './support/site.mjs';
import { E2E_SECRET, totp } from './support/totp.mjs';
import { logIn } from './support/login.mjs';

const WCAG = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * @param {import('@playwright/test').Page} page
 */
async function expectNoViolations( page ) {
	const results = await new AxeBuilder( { page } )
		.include( 'main' )
		.withTags( WCAG )
		.analyze();
	expect(
		results.violations,
		JSON.stringify( results.violations, null, 2 )
	).toEqual( [] );
}

test.describe( 'two-factor authentication', () => {
	/** @type {string[]} */
	let recoveryCodes = [];

	test.beforeEach( () => {
		// Fresh state per test: replay protection reset, no trusted devices, new codes.
		recoveryCodes = JSON.parse( site( '2fa-on' ) ).codes;
	} );

	test( 'the password alone is not enough; the code completes the login', async ( {
		page,
	} ) => {
		await logIn( page, TWO_FACTOR_USER );
		await expect( page ).toHaveURL( /action=2fa/ );
		await expect( page.locator( 'h1' ) ).toBeFocused();
		await expectNoViolations( page );

		const code = page.locator( '#portier-code' );
		await expect( code ).toHaveAttribute( 'autocomplete', 'one-time-code' );
		await expect( code ).toHaveAttribute( 'inputmode', 'numeric' );
		await code.fill( '000000' );
		await page.locator( '[data-portier-submit]' ).click();
		await expect( page.locator( '#portier-errors' ) ).toBeFocused();
		await expect( code ).toHaveAttribute( 'aria-invalid', 'true' );

		await code.fill( totp( E2E_SECRET ) );
		await page.locator( '[data-portier-submit]' ).click();
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'the six boxes mirror the single real input', async ( { page } ) => {
		await logIn( page, TWO_FACTOR_USER );
		await expect( page ).toHaveURL( /action=2fa/ );
		await page.locator( '#portier-code' ).pressSequentially( '12a3' );
		const boxes = page.locator( '.portier-otp__slot' );
		await expect( boxes ).toHaveCount( 6 );
		await expect( boxes.nth( 2 ) ).toHaveText( '3' );
		await expect( page.locator( '.portier-otp__slots' ) ).toHaveAttribute(
			'aria-hidden',
			'true'
		);
	} );

	test( 'a recovery code works when the phone is gone', async ( {
		page,
	} ) => {
		await logIn( page, TWO_FACTOR_USER );
		await expect( page ).toHaveURL( /action=2fa/ );
		await page.locator( 'a[href*="action=recovery"]' ).click();
		await expect( page ).toHaveURL( /action=recovery/ );
		await expectNoViolations( page );

		await page
			.locator( '#portier-recovery_code' )
			.fill( recoveryCodes[ 0 ].toLowerCase() );
		await page.locator( '[data-portier-submit]' ).click();
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'a trusted device skips the code next time', async ( {
		page,
		context,
	} ) => {
		await logIn( page, TWO_FACTOR_USER );
		await expect( page ).toHaveURL( /action=2fa/ );
		await page.locator( '#portier-code' ).fill( totp( E2E_SECRET ) );
		await page.locator( '#portier-trust_device' ).check();
		await page.locator( '[data-portier-submit]' ).click();
		await expect( page ).toHaveURL( /wp-admin/ );

		// "Log out" by dropping every cookie except the device cookie.
		const device = ( await context.cookies() ).filter(
			( cookie ) => cookie.name === 'portier_trusted_device'
		);
		expect( device ).toHaveLength( 1 );
		expect( device[ 0 ].httpOnly ).toBe( true );
		await context.clearCookies();
		await context.addCookies( device );

		await logIn( page, TWO_FACTOR_USER );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'required roles are guided through the setup', async ( { page } ) => {
		site( '2fa-off', SETUP_USER.login );
		await logIn( page, SETUP_USER );
		await expect( page ).toHaveURL( /action=2fa-setup/ );
		await expect( page.locator( '.portier-qr svg' ) ).toBeVisible();
		await expectNoViolations( page );

		const secret = await page
			.locator( '[data-portier-copy]' )
			.getAttribute( 'data-portier-copy' );
		await page.locator( '#portier-code' ).fill( totp( secret ) );
		await page.locator( '[data-portier-submit]' ).click();

		await expect( page ).toHaveURL( /action=recovery-codes/ );
		await expect( page.locator( '.portier-codes li' ) ).toHaveCount( 10 );
		await expect( page.locator( '[data-portier-codes]' ) ).toBeVisible();
		await expectNoViolations( page );

		// Saving must be confirmed.
		await page.locator( '[data-portier-submit]' ).click();
		await expect( page.locator( '#portier-codes_saved' ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		await page.locator( '#portier-codes_saved' ).check();
		await page.locator( '[data-portier-submit]' ).click();
		await expect( page ).toHaveURL( /wp-admin/ );
	} );
} );

test.describe( 'magic link', () => {
	test( 'the typed address is carried over and the answer is neutral', async ( {
		page,
	} ) => {
		await page.goto( LOGIN_PATH );
		await page.locator( '#portier-log' ).fill( 'nobody@example.org' );
		await page.locator( 'a[href*="action=magiclink"]' ).click();
		await expect( page ).toHaveURL( /action=magiclink/ );
		await expect( page.locator( '#portier-user_login' ) ).toHaveValue(
			'nobody@example.org'
		);
		await expectNoViolations( page );

		await page.locator( '[data-portier-submit]' ).click();
		await expect( page ).toHaveURL( /checkemail=magic/ );
	} );

	test( 'the link opens a confirmation page and works once', async ( {
		page,
	} ) => {
		const link = site( 'magic-link', USER.login );
		await page.goto( link );
		await expect( page ).not.toHaveURL( /token=/ );
		// Names the account, so people notice a login they did not start.
		await expect( page.locator( 'main' ) ).toContainText(
			`${ USER.login }@example.org`
		);
		await expectNoViolations( page );

		await page.locator( '[data-portier-submit]' ).click();
		await expect( page ).toHaveURL( /wp-admin/ );

		const again = await page.goto( link );
		expect( again.status() ).toBe( 410 );
	} );
} );
