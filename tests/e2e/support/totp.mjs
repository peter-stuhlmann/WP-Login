/**
 * TOTP codes (RFC 6238, HMAC-SHA1, 6 digits, 30 s) for the end-to-end tests – the same
 * algorithm an authenticator app uses, written independently of the plugin's PHP code.
 */
import { createHmac } from 'node:crypto';

/** Secret the PHP helper (wp-cli.php) stores for "portier-e2e-2fa". */
export const E2E_SECRET = 'PORTIERE2ESECRETKEYFORTESTSAAAAA';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * @param {string} secret Base32, spaces and lower case allowed.
 * @return {Buffer} Raw key bytes.
 */
function base32Decode( secret ) {
	let bits = '';
	for ( const char of secret.toUpperCase().replace( /[\s=]/g, '' ) ) {
		const value = ALPHABET.indexOf( char );
		if ( value < 0 ) {
			throw new Error( `Invalid base32 character: ${ char }` );
		}
		bits += value.toString( 2 ).padStart( 5, '0' );
	}
	const bytes = bits.match( /.{8}/g ) || [];
	return Buffer.from( bytes.map( ( byte ) => parseInt( byte, 2 ) ) );
}

/**
 * @param {string} secret
 * @param {number} [time] Unix time in seconds.
 * @return {string} The 6-digit code.
 */
export function totp( secret, time = Date.now() / 1000 ) {
	const counter = Buffer.alloc( 8 );
	counter.writeBigUInt64BE( BigInt( Math.floor( time / 30 ) ) );
	const hash = createHmac( 'sha1', base32Decode( secret ) )
		.update( counter )
		.digest();
	// Dynamic truncation (RFC 4226, section 5.3) is defined with bit masks.
	/* eslint-disable no-bitwise */
	const offset = hash[ hash.length - 1 ] & 0x0f;
	const binary = hash.readUInt32BE( offset ) & 0x7fffffff;
	/* eslint-enable no-bitwise */
	return String( binary % 1000000 ).padStart( 6, '0' );
}
