/**
 * Remembers the real layout for the loading placeholders (src/Admin/Skeleton.php).
 *
 * After a tab has rendered, two things are stored per tab and window width:
 * - the heights of the cards in each column (`h`), turned into a style sheet before the
 *   placeholders paint, so nothing moves when the finished screen replaces them;
 * - the shapes inside each card (`s`): text lines, controls, framed and filled areas, drawn
 *   as placeholders at exactly the places where the real elements will appear.
 * Storage key, data format and column order must match Skeleton::script(), Skeleton::render()
 * and Skeleton::shapesScript().
 */

/** Columns whose children (cards) are measured – in document order. */
export const COLUMNS =
	'.portier-column, .portier-methods, .portier-methods__aside, .portier-design__settings, .portier-design__preview';

/** Pictures: always one solid block. */
const MEDIA = 'img, svg, iframe, .portier-logo';

/**
 * Controls: a solid block when they are filled (primary buttons, switches, code editor); when
 * white with a border (fields, chips, outline buttons) a frame with their text inside; plain
 * text buttons and links just their text.
 */
const CONTROLS =
	'input:not([type="hidden"]), select, textarea, button, a.portier-btn, [role="switch"], .portier-switch';

/** At most this many tab/width layouts are kept; older ones are dropped. */
const MAX_LAYOUTS = 20;

/**
 * Storage key for a tab at a window width. Exact width: text wraps differently at every pixel.
 *
 * @param {string}  tab
 * @param {number}  width  window.innerWidth.
 * @param {boolean} folded Whether the admin menu is collapsed (other content width).
 * @return {string} localStorage key.
 */
export function layoutKey( tab, width, folded = false ) {
	return `portier-skeleton:${ tab }:${ width }${ folded ? ':folded' : '' }`;
}

/**
 * Heights of the cards in each column of the rendered tab.
 *
 * @param {Element} body The settings body (.portier-body).
 * @return {number[][]} Column => card heights in px.
 */
export function measureLayout( body ) {
	return [ ...body.querySelectorAll( COLUMNS ) ].map( ( column ) =>
		// Unrounded: even small roundings add up to a visible pixel further down.
		[ ...column.children ].map(
			( child ) => child.getBoundingClientRect().height
		)
	);
}

/**
 * Shapes inside a card, relative to its padding box.
 *
 * Kinds: "t" text line, "b" solid block (control, icon, image), "p" filled area,
 * "o" framed area. Each shape: [kind, x, y, width, height, radius].
 *
 * @param {Element} card
 * @param {Window}  win
 * @return {Array<Array<string|number>>} Shapes in document order (parents before children).
 */
export function measureShapes( card, win = window ) {
	// Relative to the padding box: that is where absolutely positioned shapes start.
	const box = card.getBoundingClientRect();
	const origin = {
		left: box.left + ( card.clientLeft || 0 ),
		top: box.top + ( card.clientTop || 0 ),
	};
	const half = ( value ) => Math.round( value * 2 ) / 2;
	const shapes = [];
	const add = ( kind, rect, radius = 0 ) => {
		if ( rect.width < 2 || rect.height < 2 ) {
			return;
		}
		shapes.push( [
			kind,
			half( rect.left - origin.left ),
			half( rect.top - origin.top ),
			half( rect.width ),
			half( rect.height ),
			half( Math.min( radius, rect.height / 2 ) ),
		] );
	};
	const visit = ( element ) => {
		for ( const node of element.childNodes ) {
			if ( node.nodeType === 3 ) {
				if ( node.textContent.trim() ) {
					const range = win.document.createRange();
					range.selectNodeContents( node );
					for ( const line of range.getClientRects?.() || [] ) {
						add( 't', line );
					}
				}
				continue;
			}
			if ( node.nodeType !== 1 ) {
				continue;
			}
			const style = win.getComputedStyle( node );
			if (
				style.display === 'none' ||
				style.visibility === 'hidden' ||
				style.opacity === '0' ||
				// Blurred decoration (e.g. a glow) has no clear shape to draw.
				( style.filter && style.filter !== 'none' ) ||
				node.classList.contains( 'screen-reader-text' )
			) {
				continue;
			}
			const rect = node.getBoundingClientRect();
			const radius =
				parseFloat( style.borderTopLeftRadius || style.borderRadius ) ||
				0;
			if ( node.matches( MEDIA ) ) {
				add( 'b', rect, radius );
				continue;
			}
			const background = style.backgroundColor;
			const filled =
				( background &&
					! [
						'transparent',
						'rgba(0, 0, 0, 0)',
						'rgb(255, 255, 255)',
					].includes( background ) ) ||
				( style.backgroundImage && style.backgroundImage !== 'none' );
			if ( filled && node.matches( CONTROLS ) ) {
				add( 'b', rect, radius );
				continue;
			}
			if ( filled ) {
				add( 'p', rect, radius );
			} else if (
				parseFloat( style.borderTopWidth ) > 0 &&
				style.borderTopStyle !== 'none'
			) {
				add( 'o', rect, radius );
			}
			visit( node );
		}
	};
	visit( card );
	return shapes;
}

/**
 * Stores the current layout of a tab. Fails silently (private mode, full storage).
 *
 * @param {string}  tab
 * @param {Element} root    #portier-admin-root
 * @param {Storage} storage
 * @param {Window}  win
 */
export function rememberLayout(
	tab,
	root,
	storage = window.localStorage,
	win = window
) {
	const body = root?.querySelector( '.portier-body' );
	if ( ! body ) {
		return;
	}
	try {
		const columns = [ ...body.querySelectorAll( COLUMNS ) ];
		storage.setItem(
			layoutKey(
				tab,
				win.innerWidth,
				win.document.body.classList.contains( 'folded' )
			),
			JSON.stringify( {
				t: Date.now(),
				h: measureLayout( body ),
				s: columns.map( ( column ) =>
					[ ...column.children ].map( ( card ) =>
						measureShapes( card, win )
					)
				),
			} )
		);
		prune( storage );
	} catch {
		// The placeholders then use their default heights and shapes.
	}
}

/**
 * Keeps the newest MAX_LAYOUTS layouts (one per tab and window width).
 *
 * @param {Storage} storage
 */
function prune( storage ) {
	const layouts = [];
	for ( let index = 0; index < storage.length; index++ ) {
		const key = storage.key( index );
		if ( key?.startsWith( 'portier-skeleton:' ) ) {
			let time = 0;
			try {
				time = JSON.parse( storage.getItem( key ) )?.t || 0;
			} catch {
				// Unreadable entries go first.
			}
			layouts.push( { key, time } );
		}
	}
	layouts
		.sort( ( a, b ) => b.time - a.time )
		.slice( MAX_LAYOUTS )
		.forEach( ( { key } ) => storage.removeItem( key ) );
}
