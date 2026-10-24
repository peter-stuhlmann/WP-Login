/**
 * Environment of the end-to-end tests. Override with environment variables.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Trailing slash + relative paths: the site may live in a subdirectory.
export const BASE_URL = (
	process.env.PORTIER_E2E_URL || 'http://localhost/wp-playground'
).replace( /\/?$/, '/' );
export const LOGIN_PATH = process.env.PORTIER_E2E_LOGIN_PATH || 'login/';
export const USER = { login: 'portier-e2e', password: 'e2e-Correct-Horse-42' };
export const ADMIN = {
	login: 'portier-e2e-admin',
	password: 'e2e-Correct-Horse-42',
};

const PHP = process.env.PORTIER_E2E_PHP || 'C:/xampp/php/php.exe';
const WP_LOAD =
	process.env.PORTIER_E2E_WP_LOAD ||
	fileURLToPath(
		new URL( '../../../../../../wp-load.php', import.meta.url )
	);
const HELPER = fileURLToPath( new URL( './wp-cli.php', import.meta.url ) );

/** Users with a second factor (see wp-cli.php). */
export const TWO_FACTOR_USER = {
	login: 'portier-e2e-2fa',
	password: 'e2e-Correct-Horse-42',
};
export const SETUP_USER = {
	login: 'portier-e2e-setup',
	password: 'e2e-Correct-Horse-42',
};

/**
 * Runs the PHP helper against the site under test (commands: see wp-cli.php).
 *
 * @param {'setup'|'teardown'|'2fa-on'|'2fa-off'|'magic-link'|'steps'} command
 * @param {...string}                                                  args
 * @return {string} The helper's output, trimmed.
 */
export function site( command, ...args ) {
	return execFileSync( PHP, [ HELPER, WP_LOAD, command, ...args ], {
		encoding: 'utf8',
	} ).trim();
}
