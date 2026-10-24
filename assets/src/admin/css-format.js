/**
 * Formatting and checking of custom CSS for the editor in the Design tab.
 *
 * Formatting uses js-beautify (MIT): one declaration per line, tabs, an empty line between rules.
 * Plain functions without React, so they can be tested on their own.
 */
import { __ } from '@wordpress/i18n';
import * as beautifyLib from 'js-beautify/js/lib/beautify-css.js';

const beautify = beautifyLib.css_beautify || beautifyLib.default?.css_beautify;

/**
 * @param {string} css
 * @return {string} Formatted CSS (unchanged when it is empty).
 */
export function formatCss( css ) {
	if ( ! css.trim() ) {
		return css;
	}
	return beautify( css, {
		indent_with_tabs: true,
		newline_between_rules: true,
		selector_separator_newline: true,
		end_with_newline: false,
		space_around_combinator: true,
	} );
}

/**
 * Lint problems that make the CSS invalid, as short lines for the warning ("Line 3: …").
 *
 * @param {Array<{severity: string, message: string, from?: {line: number}}>} annotations CodeMirror lint annotations.
 * @return {Array<{line: number, message: string}>} Errors, sorted by line.
 */
export function cssErrors( annotations = [] ) {
	return annotations
		.filter( ( annotation ) => annotation.severity === 'error' )
		.map( ( annotation ) => ( {
			line: ( annotation.from?.line ?? 0 ) + 1,
			message: explain(
				String( annotation.message || '' ).replace(
					/\s*at line \d+, col \d+\.?$/,
					''
				)
			),
		} ) )
		.sort( ( a, b ) => a.line - b.line );
}

/**
 * CSSLint's most common messages in plain words; others stay as they are.
 *
 * @param {string} message
 * @return {string} Message for people.
 */
export function explain( message ) {
	const known = [
		[
			/^Expected RBRACE/,
			__( 'A closing brace } is missing.', 'portier' ),
		],
		[
			/^Expected LBRACE/,
			__( 'An opening brace { is missing.', 'portier' ),
		],
		[
			/^Expected COLON/,
			__( 'A colon : is missing after the property.', 'portier' ),
		],
		[
			/^Expected RPAREN/,
			__( 'A closing parenthesis ) is missing.', 'portier' ),
		],
		[
			/^Unexpected token '\}'/,
			__( 'There is a closing brace } too many.', 'portier' ),
		],
		[ /^Unexpected token/, __( 'Unexpected character.', 'portier' ) ],
		[ /^Unknown property/, __( 'Unknown property.', 'portier' ) ],
		[ /^Expected .* but found/, __( 'Invalid value.', 'portier' ) ],
	];
	const match = known.find( ( [ pattern ] ) => pattern.test( message ) );
	return match ? `${ match[ 1 ] } (${ message })` : message;
}
