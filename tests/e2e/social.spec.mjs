/**
 * Social login against the local test identity provider (support/fake-idp.php): the real
 * browser round trip with redirects, state cookie, code exchange and signed ID token.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { ADMIN, LOGIN_PATH, USER } from './support/site.mjs';

const button = ( page ) => page.locator( '[data-provider="e2eidp"]' );

/**
 * Clicks the provider button and agrees on the provider's page.
 *
 * @param {import('@playwright/test').Page}                         page
 * @param {{email: string, verified?: boolean, remember?: boolean}} options
 */
async function continueWithProvider(
	page,
	{ email, verified = true, remember = false }
) {
	await page.goto( LOGIN_PATH );
	if ( remember ) {
		await page.locator( '#portier-rememberme' ).check();
	}
	await button( page ).click();
	await expect( page.locator( 'h1' ) ).toHaveText( 'Test IdP' );
	await page.locator( 'input[name="email"]' ).fill( email );
	if ( ! verified ) {
		await page.locator( 'input[name="verified"]' ).uncheck();
	}
	await page.getByRole( 'button', { name: 'Allow' } ).click();
}

test( 'the button is accessible and named after the provider', async ( {
	page,
} ) => {
	await page.goto( LOGIN_PATH );
	await expect( button( page ) ).toHaveAccessibleName( /Test IdP/ );
	const box = await button( page ).boundingBox();
	expect( box.height ).toBeGreaterThanOrEqual( 44 );
	const results = await new AxeBuilder( { page } )
		.include( '.portier-social' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect( results.violations ).toEqual( [] );
} );

test( 'a verified email logs into the existing account', async ( {
	page,
	context,
} ) => {
	// One step (default): "Keep me logged in" sits on the same screen as the buttons.
	await continueWithProvider( page, {
		email: `${ USER.login }@example.org`,
		remember: true,
	} );
	await expect( page ).toHaveURL( /wp-admin/ );

	// "Keep me logged in" was carried over: the auth cookie is persistent.
	const auth = ( await context.cookies() ).find( ( cookie ) =>
		cookie.name.startsWith( 'wordpress_logged_in_' )
	);
	expect( auth.expires ).toBeGreaterThan( Date.now() / 1000 + 86400 );
	// The one-time state cookie is gone.
	expect(
		( await context.cookies() ).some(
			( cookie ) => cookie.name === 'portier_oauth'
		)
	).toBe( false );
} );

test( 'cancelling returns to the login with a calm message', async ( {
	page,
} ) => {
	await page.goto( LOGIN_PATH );
	await button( page ).click();
	await page.getByRole( 'link', { name: 'Cancel' } ).click();
	await expect( page ).toHaveURL( /notice=oauth-cancelled/ );
	await expect( page.getByRole( 'status' ).first() ).toContainText(
		'Test IdP'
	);
} );

test( 'unverified addresses are not linked', async ( { page } ) => {
	// An account that was never linked (a linked one logs in by its provider id).
	await continueWithProvider( page, {
		email: `${ USER.login }-2fa@example.org`,
		verified: false,
	} );
	await expect( page ).toHaveURL( /notice=oauth-email/ );
	await expect( page.getByRole( 'alert' ).first() ).toBeVisible();
} );

test( 'administrators must use their password', async ( { page } ) => {
	await continueWithProvider( page, {
		email: `${ ADMIN.login }@example.org`,
	} );
	await expect( page ).toHaveURL( /notice=oauth-blocked/ );
	await expect( page.locator( '#portier-log' ) ).toBeVisible();
} );

test( 'unknown people get no account when sign-up is off', async ( {
	page,
} ) => {
	await continueWithProvider( page, { email: 'stranger@example.org' } );
	await expect( page ).toHaveURL( /notice=oauth-no-account/ );
} );

test( 'a reused callback URL does not log in', async ( { page, context } ) => {
	await page.goto( LOGIN_PATH );
	await button( page ).click();
	await page
		.locator( 'input[name="email"]' )
		.fill( `${ USER.login }@example.org` );
	const [ callback ] = await Promise.all( [
		page.waitForRequest( ( request ) =>
			request.url().includes( '/oauth/e2eidp/' )
		),
		page.getByRole( 'button', { name: 'Allow' } ).click(),
	] );
	await expect( page ).toHaveURL( /wp-admin/ );

	await context.clearCookies();
	await page.goto( callback.url() );
	await expect( page ).toHaveURL( /notice=oauth-state/ );
} );
