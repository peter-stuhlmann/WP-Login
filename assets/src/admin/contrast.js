/**
 * WCAG 2.2 contrast checks for the design editor (Figma "Barrierefreiheits-Check").
 *
 * The tokens come from the live preview (computed custom properties of the real page), so the
 * check is right for every template, colour scheme, custom colour and custom CSS.
 */

/**
 * Parses "#rgb", "#rrggbb", "rgb(…)"/"rgba(…)" (comma or space syntax, alpha as number or %).
 *
 * @param {string} value
 * @return {{r: number, g: number, b: number, a: number}|null} Colour or null.
 */
export function parseColor( value ) {
	const text = String( value || '' )
		.trim()
		.toLowerCase();
	let match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/.exec( text );
	if ( match ) {
		const hex =
			match[ 1 ].length === 3
				? match[ 1 ].replace( /./g, ( c ) => c + c )
				: match[ 1 ];
		return {
			r: parseInt( hex.slice( 0, 2 ), 16 ),
			g: parseInt( hex.slice( 2, 4 ), 16 ),
			b: parseInt( hex.slice( 4, 6 ), 16 ),
			a: 1,
		};
	}
	match =
		/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+)(%?))?\s*\)$/.exec(
			text
		);
	if ( match ) {
		let alpha = match[ 4 ] === undefined ? 1 : parseFloat( match[ 4 ] );
		if ( match[ 5 ] === '%' ) {
			alpha /= 100;
		}
		return {
			r: +match[ 1 ],
			g: +match[ 2 ],
			b: +match[ 3 ],
			a: Math.min( 1, Math.max( 0, alpha ) ),
		};
	}
	return null;
}

/**
 * Colour seen when a (semi-transparent) colour lies on an opaque background.
 *
 * @param {Object} top
 * @param {Object} bottom
 * @return {Object} Opaque colour.
 */
export function composite( top, bottom ) {
	const mix = ( channel ) =>
		Math.round(
			top[ channel ] * top.a + bottom[ channel ] * ( 1 - top.a )
		);
	return { r: mix( 'r' ), g: mix( 'g' ), b: mix( 'b' ), a: 1 };
}

function luminance( { r, g, b } ) {
	const linear = ( channel ) => {
		const c = channel / 255;
		return c <= 0.03928 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
	};
	return 0.2126 * linear( r ) + 0.7152 * linear( g ) + 0.0722 * linear( b );
}

/**
 * Contrast ratio of two opaque colours (1–21).
 *
 * @param {Object} a
 * @param {Object} b
 * @return {number} Ratio.
 */
export function ratio( a, b ) {
	const [ light, dark ] = [ luminance( a ), luminance( b ) ].sort(
		( x, y ) => y - x
	);
	return ( light + 0.05 ) / ( dark + 0.05 );
}

/**
 * The checks shown in the editor.
 *
 * @param {Object<string,string>} tokens Raw values of the --portier-color-* properties.
 * @return {Array<{key: string, ratio: number, min: number, level: string}>} Results; level is "AAA", "AA" or "fail".
 */
export function contrastChecks( tokens ) {
	const color = ( name ) => parseColor( tokens[ name ] );
	const bg = color( 'bg' ) || { r: 255, g: 255, b: 255, a: 1 };
	const solid = ( c ) => ( c ? composite( c, bg ) : null );
	const card = solid( color( 'glass' ) ) || bg;
	const field = solid( color( 'field' ) ) || card;
	const pairs = [
		// key, foreground, background, minimum (4.5 text, 3 non-text), AAA threshold
		[ 'text', color( 'text' ), card, 4.5, 7 ],
		[ 'muted', color( 'muted' ), card, 4.5, 7 ],
		[ 'accent', color( 'accent' ), card, 4.5, 7 ],
		[ 'border', color( 'border' ), field, 3, 4.5 ],
		[ 'focus', color( 'focus' ), card, 3, 4.5 ],
		[
			'button',
			color( 'on-primary' ),
			solid( color( 'primary' ) ),
			4.5,
			7,
		],
		[ 'error', color( 'error' ), card, 4.5, 7 ],
	];
	return pairs
		.filter( ( [ , fg, back ] ) => fg && back )
		.map( ( [ key, fg, back, min, aaa ] ) => {
			const value = ratio( composite( fg, back ), back );
			let level = 'fail';
			if ( value >= aaa ) {
				level = 'AAA';
			} else if ( value >= min ) {
				level = 'AA';
			}
			return { key, ratio: Math.round( value * 10 ) / 10, min, level };
		} );
}

/** Token names read from the preview (without the --portier-color- prefix). */
export const CONTRAST_TOKENS = [
	'bg',
	'glass',
	'field',
	'text',
	'muted',
	'accent',
	'border',
	'focus',
	'primary',
	'on-primary',
	'error',
];

/**
 * "#rrggbb" for a token value (alpha ignored), or ''.
 *
 * @param {string} value
 * @return {string} Hex colour.
 */
export function toHex( value ) {
	const color = parseColor( value );
	if ( ! color ) {
		return '';
	}
	return (
		'#' +
		[ color.r, color.g, color.b ]
			.map( ( channel ) => channel.toString( 16 ).padStart( 2, '0' ) )
			.join( '' )
	);
}
