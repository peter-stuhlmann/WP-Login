import { describe, expect, it } from 'vitest';
import {
	layoutKey,
	measureLayout,
	measureShapes,
	rememberLayout,
} from '../../assets/src/admin/skeleton';

/**
 * jsdom has no layout: give elements a fixed height.
 *
 * @param {Element} element
 * @param {number}  height
 */
const sized = ( element, height ) => {
	element.getBoundingClientRect = () => ( { height } );
	return element;
};

const render = () => {
	document.body.innerHTML = `<div id="root"><div class="portier-body">
		<div class="portier-columns">
			<div class="portier-column"><section></section><section></section></div>
			<div class="portier-column"><section></section></div>
		</div></div></div>`;
	const [ a, b, c ] = document.querySelectorAll( 'section' );
	sized( a, 369.4 );
	sized( b, 180 );
	sized( c, 440.6 );
	return document.getElementById( 'root' );
};

describe( 'layoutKey', () => {
	it( 'is exact per window width and menu state (same key as Skeleton::script())', () => {
		expect( layoutKey( 'security', 1280 ) ).toBe(
			'portier-skeleton:security:1280'
		);
		expect( layoutKey( 'security', 1280, true ) ).toBe(
			'portier-skeleton:security:1280:folded'
		);
	} );
} );

describe( 'measureLayout', () => {
	it( 'lists the exact card heights per column in document order', () => {
		const root = render();
		expect(
			measureLayout( root.querySelector( '.portier-body' ) )
		).toEqual( [ [ 369.4, 180 ], [ 440.6 ] ] );
	} );
} );

/**
 * Minimal Storage on a Map.
 *
 * @param {Object} entries Initial entries.
 * @return {Storage} Storage-like object.
 */
const memoryStorage = ( entries = {} ) => {
	const map = new Map( Object.entries( entries ) );
	return {
		get length() {
			return map.size;
		},
		key: ( index ) => [ ...map.keys() ][ index ] ?? null,
		getItem: ( key ) => ( map.has( key ) ? map.get( key ) : null ),
		setItem: ( key, value ) => map.set( key, String( value ) ),
		removeItem: ( key ) => map.delete( key ),
		map,
	};
};

describe( 'rememberLayout', () => {
	it( 'stores heights and shapes under the key of tab and window width', () => {
		const root = render();
		const storage = memoryStorage();
		rememberLayout( 'security', root, storage );
		const [ [ key, value ] ] = [ ...storage.map ];
		expect( key ).toBe(
			`portier-skeleton:security:${ window.innerWidth }`
		);
		const data = JSON.parse( value );
		expect( data.h ).toEqual( [ [ 369.4, 180 ], [ 440.6 ] ] );
		expect( data.s ).toHaveLength( 2 );
		expect( data.t ).toBeGreaterThan( 0 );
	} );

	it( 'keeps only the newest 20 layouts', () => {
		const old = Object.fromEntries(
			Array.from( { length: 25 }, ( _, index ) => [
				`portier-skeleton:advanced:${ 1000 + index }`,
				JSON.stringify( { t: index + 1, h: [] } ),
			] )
		);
		const storage = memoryStorage( { ...old, unrelated: 'stays' } );
		rememberLayout( 'security', render(), storage );
		const kept = [ ...storage.map.keys() ];
		expect(
			kept.filter( ( key ) => key.startsWith( 'portier-skeleton:' ) )
		).toHaveLength( 20 );
		expect( kept ).toContain( 'unrelated' );
		expect( kept ).toContain( 'portier-skeleton:advanced:1024' );
		expect( kept ).not.toContain( 'portier-skeleton:advanced:1000' );
	} );

	it( 'fails silently when storage is not available', () => {
		const storage = {
			setItem: () => {
				throw new Error( 'QuotaExceededError' );
			},
		};
		expect( () =>
			rememberLayout( 'security', render(), storage )
		).not.toThrow();
	} );
} );

describe( 'measureShapes', () => {
	/**
	 * jsdom has no layout: positions come from data attributes.
	 *
	 * @param {Element} element
	 */
	const place = ( element ) => {
		const [ x, y, width, height ] = element.dataset.box
			.split( ',' )
			.map( Number );
		element.getBoundingClientRect = () => ( {
			left: x,
			top: y,
			width,
			height,
		} );
	};

	it( 'records text lines, controls and framed areas relative to the card', () => {
		document.body.innerHTML = `
			<section id="card" data-box="100,50,400,300" style="border: 1px solid #ccc">
				<h2 data-box="121,71,200,22" data-line="121,71,90,22">Security</h2>
				<div data-box="121,110,358,80" style="border: 1px solid #ddd; border-radius: 8px">
					<button data-box="131,120,44,24" style="background: #3858e9; border-radius: 12px">x</button>
					<button data-box="400,120,70,20" style="background: transparent; border: 0" data-line="400,122,70,16">Configure</button>
				</div>
				<input type="radio" data-box="131,200,1,1" style="opacity: 0">
				<p class="screen-reader-text" data-box="0,0,1,1">Hidden</p>
			</section>`;
		document.querySelectorAll( '[data-box]' ).forEach( place );
		const card = document.getElementById( 'card' );
		Object.defineProperty( card, 'clientLeft', { value: 1 } );
		Object.defineProperty( card, 'clientTop', { value: 1 } );
		// Text lines come from the data-line of the text's parent.
		const win = {
			document: {
				createRange: () => {
					let parent = null;
					return {
						selectNodeContents: ( node ) =>
							( parent = node.parentElement ),
						getClientRects: () => {
							const [ left, top, width, height ] = (
								parent.dataset.line || '0,0,0,0'
							)
								.split( ',' )
								.map( Number );
							return [ { left, top, width, height } ];
						},
					};
				},
			},
			getComputedStyle: ( element ) => window.getComputedStyle( element ),
		};

		expect( measureShapes( card, win ) ).toEqual( [
			[ 't', 20, 20, 90, 22, 0 ], // Heading text (one line).
			[ 'o', 20, 59, 358, 80, 8 ], // Framed area …
			[ 'b', 30, 69, 44, 24, 12 ], // … a filled button: one block …
			[ 't', 299, 71, 70, 16, 0 ], // … and a text button: only its text.
		] );
	} );
} );
