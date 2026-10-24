/**
 * Passkeys in a real browser: Chrome's virtual authenticator stands in for Face ID, Touch ID or
 * Windows Hello (WebAuthn domain of the DevTools protocol).
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { ADMIN, LOGIN_PATH, USER } from './support/site.mjs';
import { logIn } from './support/login.mjs';

const WCAG = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * Adds a platform authenticator (like a laptop's fingerprint reader) to the page's browser.
 *
 * @param {import('@playwright/test').Page} page
 * @param {boolean}                         verified Whether the "fingerprint" succeeds.
 * @return {Promise<{cdp: Object, authenticatorId: string}>} Session and authenticator.
 */
async function authenticator( page, verified = true ) {
	const cdp = await page.context().newCDPSession( page );
	await cdp.send( 'WebAuthn.enable' );
	const { authenticatorId } = await cdp.send(
		'WebAuthn.addVirtualAuthenticator',
		{
			options: {
				protocol: 'ctap2',
				transport: 'internal',
				hasResidentKey: true,
				hasUserVerification: true,
				isUserVerified: verified,
				automaticPresenceSimulation: true,
			},
		}
	);
	return { cdp, authenticatorId };
}

test( 'a passkey is added in the profile and logs in without password or code', async ( {
	page,
	context,
} ) => {
	const { cdp, authenticatorId } = await authenticator( page );
	await logIn( page, USER );
	await page.waitForURL( /wp-admin/ );

	await page.goto( 'wp-admin/profile.php' );
	const section = page.locator( '[data-portier-passkeys]' );
	await section.getByLabel( /^(Name \(optional\))$/ ).fill( 'E2E key' );
	await section
		.getByRole( 'button', { name: /Passkey hinzufügen|Add a passkey/ } )
		.click();
	await expect(
		section.locator( '[data-portier-passkey-status]' )
	).toHaveText( /hinzugefügt|added/ );
	await expect( section.locator( '.portier-passkeys__name' ) ).toHaveText(
		'E2E key'
	);
	const { credentials } = await cdp.send( 'WebAuthn.getCredentials', {
		authenticatorId,
	} );
	expect( credentials ).toHaveLength( 1 );
	expect( credentials[ 0 ].isResidentCredential ).toBe( true );

	// Logged out; the device keeps the passkey.
	await context.clearCookies();
	await page.goto( LOGIN_PATH );
	await expect(
		page.locator( 'input[autocomplete="username webauthn"]' )
	).toBeVisible();
	await page
		.getByRole( 'link', {
			name: /Mit Passkey anmelden|Log in with a passkey/,
		} )
		.click();
	await page.waitForURL( /wp-admin/ );

	// The profile shows when it was last used; delete it again.
	await page.goto( 'wp-admin/profile.php' );
	await expect( section.locator( '.portier-passkeys__meta' ) ).toContainText(
		/zuletzt genutzt|last used/
	);
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await section
		.getByRole( 'button', { name: /E2E key.*(löschen)|Delete passkey/ } )
		.click();
	await expect(
		section.locator( '[data-portier-passkey-empty]' )
	).toBeVisible();
} );

test( 'a cancelled confirmation is explained and can be tried again', async ( {
	page,
} ) => {
	// The device does not confirm (no fingerprint): the browser reports a cancelled attempt.
	await authenticator( page, false );
	await page.goto( `${ LOGIN_PATH }?action=passkey` );
	const screen = page.locator( '[data-portier-passkey-screen]' );
	await expect( screen ).toHaveClass( /is-failed/ );
	await expect( screen.getByRole( 'status' ) ).toHaveText(
		/Abgebrochen|Cancelled|nicht geprüft|could not be checked/
	);
	const retry = screen.getByRole( 'button', {
		name: /Noch einmal versuchen|Try again/,
	} );
	await expect( retry ).toBeVisible();
	await expect( retry ).toBeFocused();

	const results = await new AxeBuilder( { page } ).withTags( WCAG ).analyze();
	expect(
		results.violations,
		JSON.stringify( results.violations, null, 2 )
	).toEqual( [] );

	// "Choose another method" leads back to the login.
	await page
		.getByRole( 'link', {
			name: /Andere Methode wählen|Choose another method/,
		} )
		.click();
	await expect( page.locator( '#portier-log' ) ).toBeVisible();
} );

test.describe( 'admin', () => {
	test.beforeEach( async ( { page } ) => {
		await logIn( page, ADMIN );
		await page.waitForURL( /wp-admin/ );
	} );

	test( 'methods are sorted with the keyboard and the login page follows', async ( {
		page,
		request,
		isMobile,
	} ) => {
		await page.goto( 'wp-admin/admin.php?page=portier' );
		const handle = page.locator( '#portier-sort-passkey' );
		// Phones save in the bottom bar (Figma "Admin · Mobil").
		const saveButton = page.locator(
			isMobile ? '.portier-savebar__save' : '.portier-header__save'
		);
		const save = () =>
			Promise.all( [
				page.waitForResponse(
					( r ) =>
						r.url().includes( 'settings' ) &&
						r.request().method() === 'POST'
				),
				saveButton.click(),
			] );
		await expect( handle ).toHaveAccessibleName(
			/Position 1 |position 1 /
		);
		try {
			await handle.focus();
			await page.keyboard.press( 'End' );
			await expect( handle ).toBeFocused();
			await expect( handle ).toHaveAccessibleName(
				/(?:Position|position) (\d+) (?:von|of) \1\)/
			);
			await expect( page.locator( '.portier-toast' ) ).toContainText(
				/Passkeys/
			);
			// The preview follows: the passkey button is last now.
			await expect(
				page.locator( '.portier-preview-methods > *' ).last()
			).toContainText( /Mit Passkey anmelden|Log in with a passkey/ );
			await save();

			// A visitor (no admin cookies) sees the new order.
			const login = await ( await request.get( LOGIN_PATH ) ).text();
			const provider = login.indexOf( 'data-provider="e2eidp"' );
			expect( provider ).toBeGreaterThan( -1 );
			expect( provider ).toBeLessThan(
				login.indexOf( 'portier-passkey-button' )
			);
		} finally {
			// Back to the default order for the other tests (saved only if it changed).
			await handle.focus();
			await page.keyboard.press( 'Home' );
			if ( await saveButton.isEnabled() ) {
				await save();
			}
		}
	} );

	test( 'test connection reports accepted and wrong credentials', async ( {
		page,
	} ) => {
		await page.goto( 'wp-admin/admin.php?page=portier' );
		await page
			.getByRole( 'button', {
				name: /Konfigurieren Test IdP|Configure Test IdP/,
			} )
			.click();
		const card = page.locator( '[data-sort-id="e2eidp"]' );
		await card
			.getByRole( 'button', {
				name: /Verbindung testen|Test connection/,
			} )
			.click();
		const result = card.locator( '.portier-test' );
		await expect( result ).toHaveClass( /is-ok/ );
		await expect( result ).toContainText( /akzeptiert|accepted/ );

		await card.getByLabel( /Client-Secret|Client secret/ ).fill( 'wrong' );
		await card
			.getByRole( 'button', {
				name: /Verbindung testen|Test connection/,
			} )
			.click();
		await expect( result ).toHaveClass( /is-problem/ );
		await expect( result ).toContainText(
			/akzeptiert diese|does not accept/
		);
	} );
} );
