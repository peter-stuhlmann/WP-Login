/**
 * Automated WCAG 2.2 AA checks (axe-core) for every screen, in light and dark mode,
 * on desktop and mobile. Automated tests catch a part of all problems – the manual screen
 * reader checklist in docs/ACCESSIBILITY.md covers the rest.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { LOGIN_PATH } from './support/site.mjs';
import { logIn } from './support/login.mjs';

const SCREENS = {
	login: LOGIN_PATH,
	'lost password': `${ LOGIN_PATH }?action=lostpassword`,
	'check email': `${ LOGIN_PATH }?action=checkemail&checkemail=confirm`,
	'reset link expired': `${ LOGIN_PATH }?action=rp`,
	register: `${ LOGIN_PATH }?action=register`,
	'logged out notice': `${ LOGIN_PATH }?loggedout=true`,
};

for ( const scheme of [ 'light', 'dark' ] ) {
	for ( const [ name, path ] of Object.entries( SCREENS ) ) {
		test( `${ name } has no WCAG violations (${ scheme })`, async ( {
			page,
		} ) => {
			await page.emulateMedia( {
				colorScheme: scheme,
				reducedMotion: 'reduce',
			} );
			await page.goto( path );
			const results = await new AxeBuilder( { page } )
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
		} );
	}
}

test( 'error state has no WCAG violations', async ( { page } ) => {
	await logIn( page, {
		login: 'nobody@example.org',
		password: 'wrong-password',
	} );
	await expect( page.locator( '#portier-errors' ) ).toBeVisible();
	const results = await new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect(
		results.violations,
		JSON.stringify( results.violations, null, 2 )
	).toEqual( [] );
} );

test( 'the page is usable at 200 % zoom without horizontal scrolling', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 640, height: 900 } );
	await page.goto( LOGIN_PATH );
	await page.evaluate( () => {
		document.documentElement.style.fontSize = '200%';
	} );
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth - window.innerWidth
	);
	expect( overflow ).toBeLessThanOrEqual( 0 );
} );

test( 'all interactive elements are at least 24 × 24 px (WCAG 2.5.8)', async ( {
	page,
} ) => {
	await page.goto( LOGIN_PATH );
	const small = await page.$$eval(
		'a, button, input:not([type=hidden]), summary',
		( elements ) =>
			elements
				.filter(
					( el ) =>
						el.offsetParent !== null &&
						! el.closest( '.portier-hp' ) &&
						! el.classList.contains( 'portier-skip-link' )
				)
				.map( ( el ) => ( {
					el: el.outerHTML.slice( 0, 80 ),
					rect: el.getBoundingClientRect(),
				} ) )
				.filter( ( { rect } ) => rect.width < 24 || rect.height < 24 )
				.map( ( { el } ) => el )
	);
	expect( small ).toEqual( [] );
} );
