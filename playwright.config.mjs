import { defineConfig, devices } from '@playwright/test';
import { BASE_URL } from './tests/e2e/support/site.mjs';

/**
 * End-to-end tests against a running WordPress with Portier active.
 * Default target: the local XAMPP playground (see tests/e2e/support/site.mjs).
 */
export default defineConfig( {
	testDir: 'tests/e2e',
	globalSetup: './tests/e2e/support/global-setup.mjs',
	globalTeardown: './tests/e2e/support/global-teardown.mjs',
	fullyParallel: false, // Rate limiting is per IP: keep the order predictable.
	workers: 1,
	retries: 0,
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		locale: 'de-DE',
	},
	projects: [
		{ name: 'desktop', use: { ...devices[ 'Desktop Chrome' ] } },
		{ name: 'mobile', use: { ...devices[ 'Pixel 7' ] } },
	],
} );
