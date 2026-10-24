/**
 * Logging in through the Portier screen – in one step or in two (setting "login_steps").
 */
import { expect } from '@playwright/test';
import { LOGIN_PATH } from './site.mjs';

/**
 * Fills in the login like a person would and submits it. Works for both modes: with two steps
 * it presses "Continue" first and waits for the password screen.
 *
 * @param {import('@playwright/test').Page}   page
 * @param {{login: string, password: string}} user
 * @param {{remember?: boolean}}              [options]
 */
export async function logIn( page, user, { remember = false } = {} ) {
	await page.goto( LOGIN_PATH );
	await page.locator( '#portier-log' ).fill( user.login );
	const password = page.locator( '#portier-pwd' );
	if ( ! ( await password.isVisible() ) ) {
		await page.locator( '[data-portier-submit]' ).click();
		await expect( password ).toBeVisible();
	}
	if ( remember ) {
		await page.locator( '#portier-rememberme' ).check();
	}
	await password.fill( user.password );
	await page.locator( '[data-portier-submit]' ).click();
}
