/**
 * Every template keeps WCAG 2.2 AA – checked with axe on the start screen and the error state,
 * on desktop and mobile – and keeps the 24 px target size and the page without horizontal
 * scrolling.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { LOGIN_PATH, site } from './support/site.mjs';
import { logIn } from './support/login.mjs';

const TEMPLATES = [
	'aurora',
	'pure',
	'studio',
	'midnight',
	'journal',
	'bold',
	'cloud',
	'enterprise',
];
const WCAG = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

test.describe.configure( { mode: 'serial' } );
test.afterAll( () => site( 'template', 'aurora' ) );

for ( const template of TEMPLATES ) {
	test.describe( template, () => {
		test.beforeAll( () => site( 'template', template ) );

		test( 'start screen and error state pass axe', async ( { page } ) => {
			await page.goto( LOGIN_PATH );
			await expect( page.locator( 'html' ) ).toHaveAttribute(
				'data-portier-template',
				template
			);
			let results = await new AxeBuilder( { page } )
				.withTags( WCAG )
				.analyze();
			expect(
				results.violations,
				JSON.stringify( results.violations, null, 2 )
			).toEqual( [] );

			await logIn( page, {
				login: 'nobody@example.org',
				password: 'wrong-password',
			} );
			await expect( page.locator( '#portier-errors' ) ).toBeVisible();
			results = await new AxeBuilder( { page } )
				.withTags( WCAG )
				.analyze();
			expect(
				results.violations,
				JSON.stringify( results.violations, null, 2 )
			).toEqual( [] );
		} );

		test( 'no horizontal scrolling and 24 px targets', async ( {
			page,
		} ) => {
			await page.goto( LOGIN_PATH );
			const overflow = await page.evaluate(
				() => document.documentElement.scrollWidth - window.innerWidth
			);
			expect( overflow ).toBeLessThanOrEqual( 0 );
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
						.filter( ( el ) => {
							const rect = el.getBoundingClientRect();
							return rect.width < 24 || rect.height < 24;
						} )
						.map( ( el ) => el.outerHTML.slice( 0, 80 ) )
			);
			expect( small ).toEqual( [] );
		} );
	} );
}

test.describe( 'with a background image', () => {
	test.beforeAll( () => site( 'photo', '50' ) );
	test.afterAll( () => {
		site( 'photo-off' );
		site( 'template', 'aurora' );
	} );

	for ( const template of TEMPLATES ) {
		test( `${ template }: readable and accessible`, async ( { page } ) => {
			site( 'template', template );
			await page.goto( LOGIN_PATH );
			await expect( page.locator( 'html' ) ).toHaveAttribute(
				'data-portier-photo',
				'all'
			);
			await expect( page.locator( '.portier-photo' ) ).toHaveCSS(
				'background-image',
				/portier-e2e-background/
			);
			const results = await new AxeBuilder( { page } )
				.withTags( WCAG )
				.analyze();
			expect(
				results.violations,
				JSON.stringify( results.violations, null, 2 )
			).toEqual( [] );
			const overflow = await page.evaluate(
				() => document.documentElement.scrollWidth - window.innerWidth
			);
			expect( overflow ).toBeLessThanOrEqual( 0 );
		} );
	}
} );
