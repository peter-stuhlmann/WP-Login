/**
 * Progressive enhancement for the Portier login screens.
 *
 * Without JavaScript every screen is a normal HTML form. With it, forms and in-card links are
 * sent with `X-Portier-Request: fragment`; the server answers with JSON containing only the
 * card's HTML, which is swapped in with a view transition. Focus and announcements are managed
 * so screen reader users always know where they are.
 */

import { initPasskeys, stopPasskeys } from './passkeys';

const FRAGMENT_HEADER = {
	'X-Portier-Request': 'fragment',
	Accept: 'application/json',
};

/**
 * Wires up all interactive behaviour inside `root` (called again after every swap).
 *
 * @param {Document|Element} root
 */
export function enhance( root = document ) {
	root.querySelectorAll( '[data-portier-reveal]' ).forEach( initReveal );
	root.querySelectorAll( '[data-portier-password]' ).forEach( initCapsLock );
	root.querySelectorAll( '[data-portier-otp]' ).forEach( initOtp );
	root.querySelectorAll( '[data-portier-copy]' ).forEach( initCopy );
	root.querySelectorAll( '[data-portier-codes]' ).forEach( initCodeActions );
	root.querySelectorAll( '[data-portier-qr]' ).forEach( initQr );
	initPasskeys( root );
}

/**
 * One-time code: the real input stays in charge (focus, paste, autofill, screen readers);
 * six boxes merely mirror its value.
 *
 * @param {HTMLElement} wrapper
 */
export function initOtp( wrapper ) {
	const input = wrapper.querySelector( 'input' );
	if ( ! input || wrapper.classList.contains( 'is-enhanced' ) ) {
		return;
	}
	const slots = document.createElement( 'div' );
	slots.className = 'portier-otp__slots';
	slots.setAttribute( 'aria-hidden', 'true' );
	const boxes = [];
	for ( let i = 0; i < 6; i++ ) {
		if ( i === 3 ) {
			const separator = document.createElement( 'span' );
			separator.className = 'portier-otp__sep';
			slots.append( separator );
		}
		const box = document.createElement( 'span' );
		box.className = 'portier-otp__slot';
		boxes.push( box );
		slots.append( box );
	}
	wrapper.append( slots );
	wrapper.classList.add( 'is-enhanced' );

	const render = () => {
		const digits = input.value.replace( /\D/g, '' ).slice( 0, 6 );
		if ( input.value !== digits ) {
			input.value = digits;
		}
		boxes.forEach( ( box, index ) => {
			box.textContent = digits[ index ] || '';
			box.classList.toggle(
				'is-active',
				index === Math.min( digits.length, 5 )
			);
		} );
	};
	input.addEventListener( 'input', render );
	input.addEventListener( 'focus', () =>
		wrapper.classList.add( 'is-focused' )
	);
	input.addEventListener( 'blur', () =>
		wrapper.classList.remove( 'is-focused' )
	);
	render();
}

/**
 * Copy-to-clipboard buttons (hidden without JavaScript).
 *
 * @param {HTMLButtonElement} button
 */
export function initCopy( button ) {
	if ( button.dataset.portierReady ) {
		return;
	}
	button.dataset.portierReady = '1';
	button.hidden = false;
	button.addEventListener( 'click', async () => {
		try {
			await window.navigator.clipboard.writeText(
				button.dataset.portierCopy
			);
			announce( button.dataset.copied || '' );
		} catch {
			// Clipboard blocked: the text is visible and can be selected manually.
		}
	} );
}

/**
 * Copy / download / print for recovery codes.
 *
 * @param {HTMLElement} container
 */
export function initCodeActions( container ) {
	if ( container.dataset.portierReady ) {
		return;
	}
	container.dataset.portierReady = '1';
	container.hidden = false;
	const codes = container.dataset.portierCodes || '';
	container.addEventListener( 'click', async ( event ) => {
		const button = event.target.closest( '[data-portier-codes-action]' );
		if ( ! button ) {
			return;
		}
		const action = button.dataset.portierCodesAction;
		if ( action === 'copy' ) {
			try {
				await window.navigator.clipboard.writeText( codes );
				announce( button.dataset.done || '' );
			} catch {}
		} else if ( action === 'download' ) {
			const link = document.createElement( 'a' );
			link.href = URL.createObjectURL(
				new window.Blob( [ codes + '\n' ], { type: 'text/plain' } )
			);
			link.download = container.dataset.filename || 'recovery-codes.txt';
			link.click();
			URL.revokeObjectURL( link.href );
		} else if ( action === 'print' ) {
			window.print();
		}
	} );
}

/**
 * Renders the QR code for the authenticator app. The library is loaded only on this screen.
 *
 * @param {HTMLElement} container
 */
export async function initQr( container ) {
	if ( container.dataset.portierReady ) {
		return;
	}
	container.dataset.portierReady = '1';
	try {
		const { default: qrcode } = await import(
			/* webpackChunkName: "qr" */ 'qrcode-generator'
		);
		const qr = qrcode( 0, 'M' );
		qr.addData( container.dataset.portierQr );
		qr.make();
		container.innerHTML = qr.createSvgTag( {
			cellSize: 4,
			margin: 0,
			scalable: true,
		} );
		container.querySelector( 'svg' )?.setAttribute( 'aria-hidden', 'true' );
		container.hidden = false;
	} catch {
		// Without the QR code the manual key (always shown) still works.
	}
}

/**
 * Carries form values to the next screen, e.g. the e-mail address from the login form to
 * "send me a login link", or "Keep me logged in" to a social login
 * (data-portier-carry="field-id:param", several pairs separated by spaces).
 * Checkboxes are carried only when checked.
 *
 * @param {HTMLAnchorElement} link
 * @return {string} The link target including the carried values.
 */
export function carriedHref( link ) {
	const url = new URL( link.href );
	for ( const pair of ( link.dataset.portierCarry || '' ).split( /\s+/ ) ) {
		const [ fieldId, param ] = pair.split( ':' );
		const field = fieldId && document.getElementById( fieldId );
		if ( ! field || ! param ) {
			continue;
		}
		const value =
			field.type === 'checkbox'
				? field.checked && field.value
				: field.value;
		if ( value ) {
			url.searchParams.set( param, value );
		} else {
			url.searchParams.delete( param );
		}
	}
	return url.toString();
}

/**
 * Removes `wp_lang` from the address after a language switch. The choice is remembered in the
 * `wp_lang` cookie; left in the URL, a reload, bookmark or the back button could bring back an
 * older choice.
 *
 * @param {Window} win
 */
export function forgetLanguageParam( win = window ) {
	const url = new URL( win.location.href );
	if ( ! url.searchParams.has( 'wp_lang' ) ) {
		return;
	}
	url.searchParams.delete( 'wp_lang' );
	win.history.replaceState( win.history.state, '', url.toString() );
}

/**
 * "Show password" toggle: a real button with aria-pressed, hidden until JS is available.
 *
 * @param {HTMLButtonElement} button
 */
export function initReveal( button ) {
	const input = document.getElementById(
		button.getAttribute( 'aria-controls' )
	);
	if ( ! input || button.dataset.portierReady ) {
		return;
	}
	button.dataset.portierReady = '1';
	button.hidden = false;
	const label = button.querySelector( '.screen-reader-text' );

	button.addEventListener( 'click', () => {
		const show = input.type === 'password';
		input.type = show ? 'text' : 'password';
		button.setAttribute( 'aria-pressed', String( show ) );
		if ( label ) {
			label.textContent = show
				? button.dataset.labelHide
				: button.dataset.labelShow;
		}
	} );
}

/**
 * Shows the "Caps Lock is on" hint while typing a password and links it to the field.
 *
 * @param {HTMLInputElement} input
 */
export function initCapsLock( input ) {
	const hint = document.getElementById( `${ input.id }-caps` );
	if ( ! hint || input.dataset.portierCaps ) {
		return;
	}
	input.dataset.portierCaps = '1';

	const update = ( event ) => {
		if ( typeof event.getModifierState !== 'function' ) {
			return;
		}
		const on = event.getModifierState( 'CapsLock' );
		hint.hidden = ! on;
		const ids = new Set(
			( input.getAttribute( 'aria-describedby' ) || '' )
				.split( ' ' )
				.filter( Boolean )
		);
		if ( on ) {
			ids.add( hint.id );
		} else {
			ids.delete( hint.id );
		}
		if ( ids.size ) {
			input.setAttribute( 'aria-describedby', [ ...ids ].join( ' ' ) );
		} else {
			input.removeAttribute( 'aria-describedby' );
		}
	};
	input.addEventListener( 'keydown', update );
	input.addEventListener( 'keyup', update );
	input.addEventListener( 'blur', () => {
		hint.hidden = true;
	} );
}

/**
 * Loading state that stays accessible: the button keeps focus, gets aria-busy and the
 * loading label, and the live region announces it.
 *
 * @param {HTMLButtonElement|null} button
 * @param {boolean}                busy
 */
export function setBusy( button, busy ) {
	if ( ! button ) {
		return;
	}
	const label = button.querySelector( '.portier-button__label' );
	if ( busy ) {
		button.dataset.originalLabel = label ? label.textContent : '';
		button.setAttribute( 'aria-busy', 'true' );
		button.setAttribute( 'aria-disabled', 'true' );
		if ( label && button.dataset.loadingLabel ) {
			label.textContent = button.dataset.loadingLabel;
			announce( button.dataset.loadingLabel );
		}
	} else {
		button.removeAttribute( 'aria-busy' );
		button.removeAttribute( 'aria-disabled' );
		if ( label && button.dataset.originalLabel !== undefined ) {
			label.textContent = button.dataset.originalLabel;
		}
	}
}

/**
 * Header language switcher and page language after an in-place screen change: the switcher's
 * links must lead to the screen now shown, and `lang` must match the content (screen readers).
 *
 * @param {{lang?: string, switcher?: string}} data Fragment response.
 */
export function syncLanguage( data ) {
	if ( data.lang ) {
		document.documentElement.lang = data.lang;
	}
	const current = document.querySelector( '.portier-lang' );
	if ( typeof data.switcher !== 'string' || ! current ) {
		return;
	}
	const template = document.createElement( 'template' );
	template.innerHTML = data.switcher.trim();
	const next = template.content.firstElementChild;
	if ( next ) {
		current.replaceWith( next );
	}
}

/**
 * Header: follow-up screens show a "Back" pill instead of the logo (Figma). The header is
 * outside the swapped card, so it is updated here.
 *
 * @param {string} url Target of "Back", or '' to show the logo.
 */
export function syncBack( url ) {
	const back = document.querySelector( '[data-portier-back]' );
	const brand = document.querySelector( '[data-portier-brand]' );
	if ( ! back || ! brand ) {
		return;
	}
	if ( url ) {
		back.href = url;
	}
	back.hidden = ! url;
	brand.hidden = !! url;
}

/**
 * Polite screen reader announcement.
 *
 * @param {string} message
 */
export function announce( message ) {
	const region = document.getElementById( 'portier-live' );
	if ( ! region ) {
		return;
	}
	// Clearing first makes repeated identical messages announce again.
	region.textContent = '';
	window.setTimeout( () => {
		region.textContent = message;
	}, 50 );
}

/**
 * Parts that exist on both screens of a change and move to their new place (View Transitions).
 * Everything else in the card fades out or in on its own, so the card itself is only the box
 * that grows or shrinks.
 */
const SHARED_PARTS = {
	title: ( card ) => card.querySelector( '#portier-title' ),
	lead: ( card ) => card.querySelector( '.portier-heading .portier-lead' ),
	// The e-mail / username field: same element whether it says "Email address or username"
	// (login) or "Email address" (registration).
	identity: ( card ) =>
		[
			...card.querySelectorAll(
				'input[autocomplete^="username"], input[autocomplete="email"]'
			),
		]
			.find( ( input ) => input.getClientRects().length > 0 )
			?.closest( '.portier-field' ),
	submit: ( card ) => card.querySelector( '[data-portier-submit]' ),
	switch: ( card ) => card.querySelector( '.portier-switch' ),
};

/**
 * Gives the parts of the card their view transition names: shared parts a fixed name (they are
 * paired with the other screen), all other visible blocks a name of their own (`prefix`), so
 * they are not drawn into the card's snapshot and fade separately.
 *
 * @param {Element} card
 * @param {string}  prefix "old" or "new".
 * @return {Element[]} The named elements.
 */
export function nameParts( card, prefix ) {
	const named = [];
	const shared = new Set();
	const name = ( element, value ) => {
		element.style.viewTransitionName = value;
		element.style.viewTransitionClass = 'portier-part';
		named.push( element );
	};
	for ( const [ key, find ] of Object.entries( SHARED_PARTS ) ) {
		const element = find( card );
		if ( element ) {
			name( element, `portier-${ key }` );
			shared.add( element );
		}
	}
	let index = 0;
	const walk = ( parent ) => {
		for ( const child of parent.children ) {
			if (
				shared.has( child ) ||
				child.getClientRects().length === 0 ||
				child.classList.contains( 'portier-hp' )
			) {
				continue;
			}
			// Containers of shared parts (heading, form) are split up into their blocks.
			if ( [ ...shared ].some( ( part ) => child.contains( part ) ) ) {
				walk( child );
			} else {
				name( child, `portier-${ prefix }-${ index++ }` );
			}
		}
	};
	walk( card );
	return named;
}

/**
 * Removes the names again (after the transition), so they cannot collide with the next one.
 *
 * @param {Element[]} elements
 */
export function clearParts( elements ) {
	for ( const element of elements ) {
		element.style.viewTransitionName = '';
		element.style.viewTransitionClass = '';
	}
}

/**
 * Replaces the card content and moves focus to the right place.
 *
 * @param {{html: string, title?: string, focus?: string}} data
 * @param {{url?: string, push?: boolean}}                 options
 */
export function swap( data, { url, push = false } = {} ) {
	const card = document.getElementById( 'portier-card' );
	if ( ! card ) {
		return Promise.resolve();
	}
	const update = () => {
		// An open passkey request belongs to the old screen.
		stopPasskeys();
		card.innerHTML = data.html;
		if ( data.title ) {
			document.title = data.title;
		}
		syncBack( data.back || '' );
		syncLanguage( data );
		enhance( card );
	};
	const focus = () => {
		// "errors", "heading" or the id of a field (e.g. the password in step 2 of the login).
		const named =
			data.focus === 'errors'
				? document.getElementById( 'portier-errors' )
				: data.focus &&
					data.focus !== 'heading' &&
					document.getElementById( data.focus );
		const target =
			named || document.getElementById( 'portier-title' ) || card;
		target.focus( { preventScroll: false } );
	};

	if ( push && url && url !== window.location.href ) {
		window.history.pushState( { portier: true }, '', url );
	}

	const reduceMotion = window.matchMedia?.(
		'(prefers-reduced-motion: reduce)'
	).matches;
	if ( document.startViewTransition && ! reduceMotion ) {
		// Old and new parts are named on their own screen: shared ones pair up and move.
		nameParts( card, 'old' );
		let parts = [];
		const transition = document.startViewTransition( () => {
			update();
			parts = nameParts( card, 'new' );
		} );
		const done = () => {
			clearParts( parts );
			focus();
		};
		return transition.finished.then( done, done );
	}
	update();
	focus();
	return Promise.resolve();
}

/**
 * Loads a Portier screen in place (used for links and internal redirects).
 *
 * @param {string}  url
 * @param {boolean} push Whether to add a history entry.
 */
export async function navigate( url, push = true ) {
	let data;
	try {
		const response = await fetch( url, {
			headers: FRAGMENT_HEADER,
			credentials: 'same-origin',
		} );
		data = await response.json();
	} catch {
		window.location.assign( url ); // Anything unexpected: fall back to a normal page load.
		return;
	}
	await handleResponse( data, url, push );
}

/**
 * @param {{redirect?: string, internal?: boolean, html?: string, url?: string, title?: string, focus?: string}} data
 * @param {string}                                                                                               url
 * @param {boolean}                                                                                              push
 */
export async function handleResponse( data, url, push ) {
	if ( data.redirect ) {
		if ( data.internal ) {
			await navigate( data.redirect, true );
		} else {
			window.location.assign( data.redirect );
		}
		return;
	}
	if ( typeof data.html === 'string' ) {
		// The requested URL is exact (it keeps e.g. ?checkemail=confirm); the server URL is a fallback.
		await swap( data, { url: url || data.url, push } );
	}
}

/**
 * Submits a form via fetch; falls back to a classic submit on any unexpected problem.
 *
 * @param {HTMLFormElement} form
 * @param {SubmitEvent}     event
 */
export async function submitForm( form, event ) {
	event.preventDefault();
	const button = form.querySelector( '[data-portier-submit]' );
	if ( button?.getAttribute( 'aria-busy' ) === 'true' ) {
		return; // Ignore double submits.
	}
	setBusy( button, true );

	let data;
	try {
		const response = await fetch( form.action, {
			method: 'POST',
			body: new FormData( form ),
			headers: FRAGMENT_HEADER,
			credentials: 'same-origin',
		} );
		data = await response.json();
	} catch {
		// The native method, in case a field named "submit" shadows form.submit.
		window.HTMLFormElement.prototype.submit.call( form );
		return;
	}

	if ( ! data.redirect || data.internal ) {
		setBusy( button, false );
	}
	await handleResponse( data, form.action, false );
}

/**
 * Event delegation for the whole document – survives content swaps.
 */
export function bindGlobalEvents() {
	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest?.( '[data-portier-form]' );
		if ( form ) {
			submitForm( form, event );
		}
	} );

	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest?.( 'a[data-portier-nav]' );
		if (
			! link ||
			event.defaultPrevented ||
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}
		event.preventDefault();
		navigate( carriedHref( link ), true );
	} );

	// Links that leave the page (social login): update the target, then let the browser follow it.
	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest?.(
			'a[data-portier-carry]:not([data-portier-nav])'
		);
		if ( link ) {
			link.href = carriedHref( link );
		}
	} );

	window.addEventListener( 'popstate', () =>
		navigate( window.location.href, false )
	);

	// Language menu: Escape closes it and returns focus to the toggle.
	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key !== 'Escape' ) {
			return;
		}
		const open = document.querySelector( '.portier-lang[open]' );
		if ( open ) {
			open.removeAttribute( 'open' );
			open.querySelector( 'summary' )?.focus();
		}
	} );
	document.addEventListener( 'click', ( event ) => {
		document
			.querySelectorAll( '.portier-lang[open]' )
			.forEach( ( menu ) => {
				if ( ! menu.contains( event.target ) ) {
					menu.removeAttribute( 'open' );
				}
			} );
	} );
}

/**
 * Live preview of the design editor: receives unsaved custom CSS from the admin page (same origin,
 * parent window only) and tells it when the page is ready. Only active in preview responses.
 *
 * @param {Window} win
 */
export function listenForPreviewCss( win = window ) {
	win.addEventListener( 'message', ( event ) => {
		const data = event.data;
		if (
			event.origin !== win.location.origin ||
			event.source !== win.parent ||
			! data ||
			data.type !== 'portier:preview-css' ||
			typeof data.css !== 'string'
		) {
			return;
		}
		const style = win.document.getElementById( 'portier-custom-css' );
		if ( style ) {
			style.textContent = data.css; // Text only: cannot create markup.
		}
	} );
	win.parent?.postMessage(
		{ type: 'portier:preview-ready' },
		win.location.origin
	);
}
