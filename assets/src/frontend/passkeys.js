/**
 * Passkey login in the browser (WebAuthn).
 *
 * - Elements marked `data-portier-passkey` (button, divider, link) appear only when the browser
 *   supports passkeys.
 * - The passkey screen asks the device right away and reports progress in its status line
 *   (aria-live). "Try again" appears after a cancelled or failed attempt.
 * - Autofill: an e-mail field with `autocomplete="username webauthn"` offers the site's passkeys
 *   in the browser's suggestions (conditional mediation). Only one request can be open at a time,
 *   so autofill is stopped before the screen asks and when the card changes.
 * Binary values travel as base64url, like the server expects.
 */

/** The open passkey request (autofill or screen), so it can be stopped. */
let pending = null;

/**
 * Stops the open passkey request – before another one starts and when the card changes
 * (otherwise the device dialog would stay open for a screen that is gone).
 */
export function stopPasskeys() {
	pending?.abort();
	pending = null;
}

/**
 * Starts a new request, stopping the previous one.
 *
 * @return {AbortController} Controller of the new request.
 */
function nextRequest() {
	stopPasskeys();
	pending = new window.AbortController();
	return pending;
}

/**
 * @param {string} value base64url
 * @return {ArrayBuffer} Bytes.
 */
export function fromBase64Url( value ) {
	const base64 = value.replace( /-/g, '+' ).replace( /_/g, '/' );
	const binary = window.atob(
		base64 + '==='.slice( ( base64.length + 3 ) % 4 )
	);
	const bytes = new Uint8Array( binary.length );
	for ( let i = 0; i < binary.length; i++ ) {
		bytes[ i ] = binary.charCodeAt( i );
	}
	return bytes.buffer;
}

/**
 * @param {ArrayBuffer|null} buffer
 * @return {string} base64url without padding ('' for null).
 */
export function toBase64Url( buffer ) {
	if ( ! buffer ) {
		return '';
	}
	let binary = '';
	for ( const byte of new Uint8Array( buffer ) ) {
		binary += String.fromCharCode( byte );
	}
	return window
		.btoa( binary )
		.replace( /\+/g, '-' )
		.replace( /\//g, '_' )
		.replace( /=+$/, '' );
}

/**
 * Server options (base64url strings) → PublicKeyCredentialRequestOptions.
 *
 * @param {Object} options
 * @return {Object} Options for navigator.credentials.get().
 */
export function requestOptions( options ) {
	return {
		...options,
		challenge: fromBase64Url( options.challenge ),
		allowCredentials: ( options.allowCredentials || [] ).map(
			( credential ) => ( {
				...credential,
				id: fromBase64Url( credential.id ),
			} )
		),
	};
}

/**
 * PublicKeyCredential from get() → JSON for the server.
 *
 * @param {PublicKeyCredential} credential
 * @return {Object} Serialisable credential.
 */
export function assertionToJson( credential ) {
	const response = credential.response;
	return {
		id: credential.id,
		type: credential.type,
		response: {
			clientDataJSON: toBase64Url( response.clientDataJSON ),
			authenticatorData: toBase64Url( response.authenticatorData ),
			signature: toBase64Url( response.signature ),
			userHandle: toBase64Url( response.userHandle ),
		},
	};
}

/**
 * Whether this browser can use passkeys at all.
 *
 * @param {Window} win
 * @return {boolean} Support.
 */
export function supported( win = window ) {
	return (
		typeof win.PublicKeyCredential === 'function' &&
		!! win.navigator.credentials?.get
	);
}

/**
 * POSTs one step to the passkey endpoint.
 *
 * @param {string} endpoint
 * @param {Object} fields
 * @return {Promise<Object>} JSON answer (also for error statuses).
 */
async function post( endpoint, fields ) {
	const body = new URLSearchParams( fields );
	const response = await window.fetch( endpoint, {
		method: 'POST',
		body,
		credentials: 'same-origin',
		headers: { Accept: 'application/json' },
	} );
	return response.json();
}

/**
 * Asks for a passkey and logs in: options → device → verification → redirect.
 *
 * @param {string}                                  endpoint
 * @param {Object}                                  context           redirect_to and remember.
 * @param {Object}                                  hooks
 * @param {AbortSignal}                             [hooks.signal]
 * @param {string}                                  [hooks.mediation] "conditional" for autofill.
 * @param {(state: string, message?: string)=>void} [hooks.report]    Progress for the UI.
 * @return {Promise<boolean>} True when the login succeeded (the page then navigates).
 */
export async function loginWithPasskey( endpoint, context, hooks = {} ) {
	const report = hooks.report || ( () => {} );
	const answer = await post( endpoint, { portier_step: 'options' } );
	if ( ! answer.options ) {
		report( 'error', answer.message );
		return false;
	}
	report( 'waiting' );
	let credential;
	try {
		credential = await window.navigator.credentials.get( {
			publicKey: requestOptions( answer.options ),
			signal: hooks.signal,
			...( hooks.mediation ? { mediation: hooks.mediation } : {} ),
		} );
	} catch ( error ) {
		// Cancelled by the person, timed out, or stopped by us (AbortError).
		report( error?.name === 'AbortError' ? 'aborted' : 'cancelled' );
		return false;
	}
	if ( ! credential ) {
		report( 'cancelled' );
		return false;
	}
	report( 'checking' );
	const result = await post( endpoint, {
		portier_step: 'verify',
		credential: JSON.stringify( assertionToJson( credential ) ),
		redirect_to: context.redirectTo || '',
		remember: context.remember ? '1' : '',
	} );
	if ( ! result.redirect ) {
		report( 'error', result.message );
		return false;
	}
	report( 'success' );
	window.location.assign( result.redirect );
	return true;
}

/**
 * Shows passkey elements when supported; starts the screen or the autofill if present.
 *
 * @param {Element|Document} root
 */
export function initPasskeys( root = document ) {
	if ( ! supported() ) {
		root.querySelectorAll( '[data-portier-passkey-screen]' ).forEach(
			( screen ) => {
				const text = screen.querySelector(
					'[data-portier-passkey-text]'
				);
				if ( text ) {
					text.textContent = screen.dataset.textUnsupported || '';
				}
				screen.classList.add( 'is-failed' );
			}
		);
		return;
	}
	root.querySelectorAll( '[data-portier-passkey]' ).forEach( ( element ) => {
		element.hidden = false;
	} );
	const screen = root.querySelector( '[data-portier-passkey-screen]' );
	if ( screen ) {
		initScreen( screen );
		return;
	}
	const field = root.querySelector( 'input[autocomplete~="webauthn"]' );
	const button = root.querySelector( 'a[data-portier-passkey]' );
	if ( field && button ) {
		startAutofill( button.href );
	}
}

/**
 * Passkey autofill in the e-mail field (conditional mediation).
 *
 * @param {string} endpoint
 */
async function startAutofill( endpoint ) {
	if (
		! ( await window.PublicKeyCredential.isConditionalMediationAvailable?.() )
	) {
		return;
	}
	const controller = nextRequest();
	const url = new URL( endpoint );
	try {
		await loginWithPasskey(
			endpoint,
			{
				redirectTo: url.searchParams.get( 'redirect_to' ) || '',
				remember:
					!! document.getElementById( 'portier-rememberme' )?.checked,
			},
			{ signal: controller.signal, mediation: 'conditional' }
		);
	} catch {
		// Network problems: the normal login stays available.
	}
}

/**
 * The passkey screen: asks right away, then on "Try again".
 *
 * @param {HTMLElement} screen
 */
function initScreen( screen ) {
	if ( screen.dataset.portierReady ) {
		return;
	}
	screen.dataset.portierReady = '1';
	const text = screen.querySelector( '[data-portier-passkey-text]' );
	const retry = screen.querySelector( '[data-portier-passkey-retry]' );

	const report = ( state, message ) => {
		const messages = {
			waiting: screen.dataset.textWaiting,
			checking: screen.dataset.textChecking,
			success: screen.dataset.textSuccess,
			cancelled: screen.dataset.textCancelled,
			error: message || screen.dataset.textError,
		};
		if ( state === 'aborted' ) {
			return; // We stopped it ourselves (left the screen).
		}
		if ( text ) {
			text.textContent = messages[ state ] || '';
		}
		const failed = state === 'cancelled' || state === 'error';
		screen.classList.toggle( 'is-failed', failed );
		screen.classList.toggle(
			'is-busy',
			state === 'waiting' || state === 'checking' || state === 'success'
		);
		if ( retry ) {
			retry.hidden = ! failed;
			if ( failed ) {
				retry.focus();
			}
		}
	};

	const run = async () => {
		const controller = nextRequest();
		report( 'waiting' );
		try {
			await loginWithPasskey(
				screen.dataset.endpoint,
				{
					redirectTo: screen.dataset.redirectTo,
					remember: screen.dataset.remember === '1',
				},
				{ signal: controller.signal, report }
			);
		} catch {
			report( 'error' );
		}
	};

	retry?.addEventListener( 'click', run );
	run();
}
