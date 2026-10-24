// Copies the self-hosted fonts of all templates (SIL OFL 1.1) from node_modules into
// assets/fonts and writes assets/fonts/fonts.json, from which PHP builds the @font-face rules.
//
// Fonts are served locally: no request to a font CDN, which keeps the login page GDPR-friendly.
// Only the latin and latin-ext subsets are shipped (with unicode-range, so browsers download
// latin-ext only when a page needs it).
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const root = new URL( '../', import.meta.url );
const target = new URL( 'assets/fonts/', root );
mkdirSync( target, { recursive: true } );

/**
 * id: CSS family key used by the templates. package: npm package. css: stylesheet whose
 * latin / latin-ext rules are copied. styles: font styles to ship.
 */
const FONTS = [
	{ id: 'plus-jakarta-sans', family: 'Plus Jakarta Sans', package: '@fontsource-variable/plus-jakarta-sans', css: 'wght.css' },
	{ id: 'space-grotesk', family: 'Space Grotesk', package: '@fontsource-variable/space-grotesk', css: 'wght.css' },
	{ id: 'manrope', family: 'Manrope', package: '@fontsource-variable/manrope', css: 'wght.css' },
	{ id: 'bricolage-grotesque', family: 'Bricolage Grotesque', package: '@fontsource-variable/bricolage-grotesque', css: 'wght.css' },
	{ id: 'ibm-plex-sans', family: 'IBM Plex Sans', package: '@fontsource-variable/ibm-plex-sans', css: 'wght.css' },
	{ id: 'instrument-serif', family: 'Instrument Serif', package: '@fontsource/instrument-serif', css: [ '400.css', '400-italic.css' ] },
	{ id: 'archivo-black', family: 'Archivo Black', package: '@fontsource/archivo-black', css: '400.css' },
];

const manifest = {
	geist: {
		family: 'Geist',
		license: 'GEIST-LICENSE.txt',
		faces: [ { file: 'geist-variable.woff2', weight: '100 900', style: 'normal', range: '' } ],
	},
};

// Geist (default template) comes from the "geist" package.
copyFileSync( new URL( 'node_modules/geist/dist/fonts/geist-sans/Geist-Variable.woff2', root ), new URL( 'geist-variable.woff2', target ) );
copyFileSync( new URL( 'node_modules/geist/LICENSE.txt', root ), new URL( 'GEIST-LICENSE.txt', target ) );

for ( const font of FONTS ) {
	const dir = new URL( `node_modules/${ font.package }/`, root );
	mkdirSync( new URL( `${ font.id }/`, target ), { recursive: true } );
	const faces = [];
	for ( const sheet of [].concat( font.css ) ) {
		const css = readFileSync( new URL( sheet, dir ), 'utf8' );
		// One block per @font-face, preceded by a comment naming the subset.
		for ( const block of css.split( '@font-face' ).slice( 1 ) ) {
			const src = /url\(\.\/files\/([^)]+\.woff2)\)/.exec( block )?.[ 1 ];
			if ( ! src || ! /-latin(-ext)?-/.test( src ) ) {
				continue;
			}
			copyFileSync( new URL( `files/${ src }`, dir ), new URL( `${ font.id }/${ src }`, target ) );
			faces.push( {
				file: `${ font.id }/${ src }`,
				weight: /font-weight:\s*([^;]+);/.exec( block )[ 1 ].trim(),
				style: /font-style:\s*([^;]+);/.exec( block )[ 1 ].trim(),
				range: /unicode-range:\s*([^;]+);/.exec( block )?.[ 1 ].trim() ?? '',
			} );
		}
	}
	copyFileSync( new URL( 'LICENSE', dir ), new URL( `${ font.id }/LICENSE.txt`, target ) );
	manifest[ font.id ] = { family: font.family, license: `${ font.id }/LICENSE.txt`, faces };
}

writeFileSync( new URL( 'fonts.json', target ), JSON.stringify( manifest, null, '\t' ) + '\n' );
console.log( `Fonts copied to assets/fonts/ (${ Object.keys( manifest ).length } families).` );
