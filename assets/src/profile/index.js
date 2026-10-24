/**
 * Passkeys in the user profile (src/Admin/PasskeyProfileSection.php): add, rename, delete.
 *
 * Adding: options from the server → the browser asks the device (navigator.credentials.create)
 * → the server verifies and stores the public key. Every result is announced in a status line
 * (aria-live); after deleting, focus moves to a sensible place.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { fromBase64Url, toBase64Url } from '../frontend/passkeys';
import './profile.css';

const PATH = '/portier/v1/passkeys';

/**
 * Server options (base64url strings) → PublicKeyCredentialCreationOptions.
 *
 * @param {Object} options
 * @return {Object} Options for navigator.credentials.create().
 */
export function creationOptions( options ) {
	return {
		...options,
		challenge: fromBase64Url( options.challenge ),
		user: { ...options.user, id: fromBase64Url( options.user.id ) },
		excludeCredentials: ( options.excludeCredentials || [] ).map(
			( credential ) => ( {
				...credential,
				id: fromBase64Url( credential.id ),
			} )
		),
	};
}

/**
 * New credential → JSON for the server.
 *
 * @param {PublicKeyCredential} credential
 * @return {Object} Serialisable credential.
 */
export function attestationToJson( credential ) {
	return {
		id: credential.id,
		type: credential.type,
		response: {
			clientDataJSON: toBase64Url( credential.response.clientDataJSON ),
			attestationObject: toBase64Url(
				credential.response.attestationObject
			),
		},
	};
}

/**
 * Whether the server asks to log in again before the change (a session that is too old).
 *
 * @param {Object} error Rejection of apiFetch.
 * @return {boolean} True with a URL to log in again.
 */
export function isReauthError( error ) {
	return (
		error?.code === 'portier_reauth_required' &&
		typeof error?.data?.reauth_url === 'string'
	);
}

/**
 * @param {HTMLElement} root [data-portier-passkeys]
 */
export function initProfile( root ) {
	const own = root.dataset.own === '1';
	const user = Number( root.dataset.user );
	const list = root.querySelector( '[data-portier-passkey-list]' );
	const empty = root.querySelector( '[data-portier-passkey-empty]' );
	const status = root.querySelector( '[data-portier-passkey-status]' );
	const add = root.querySelector( '[data-portier-passkey-add]' );
	const nameField = root.querySelector( '#portier-passkey-name' );
	const create = root.querySelector( '[data-portier-passkey-create]' );
	const query = own ? '' : `?user=${ user }`;

	const say = ( message, failed = false ) => {
		status.textContent = message;
		status.classList.toggle( 'is-error', failed );
	};

	// Adding a passkey needs a fresh login (see RecentLogin): offer the way there.
	const askToLogInAgain = ( error ) => {
		say( error.message, true );
		const link = document.createElement( 'a' );
		link.href = error.data.reauth_url;
		link.textContent = __( 'Log in again', 'portier' );
		status.append( ' ', link );
	};

	// The latest list from the server (also restores a row after "Cancel").
	let rows = [];
	const render = ( passkeys ) => {
		rows = passkeys;
		list.replaceChildren( ...passkeys.map( item ) );
		empty.hidden = passkeys.length > 0;
	};

	const item = ( passkey ) => {
		const li = document.createElement( 'li' );
		li.className = 'portier-passkeys__item';
		li.dataset.id = passkey.id;

		const text = document.createElement( 'span' );
		text.className = 'portier-passkeys__text';
		const name = document.createElement( 'span' );
		name.className = 'portier-passkeys__name';
		name.textContent = passkey.name;
		const meta = document.createElement( 'span' );
		meta.className = 'portier-passkeys__meta description';
		meta.textContent = [
			/* translators: %s: date and time */
			sprintf( __( 'Added %s', 'portier' ), passkey.created ),
			passkey.used
				? /* translators: %s: date and time */
					sprintf( __( 'last used %s', 'portier' ), passkey.used )
				: __( 'not used yet', 'portier' ),
			passkey.synced ? __( 'synced', 'portier' ) : '',
		]
			.filter( Boolean )
			.join( ' · ' );
		text.append( name, meta );

		const actions = document.createElement( 'span' );
		actions.className = 'portier-passkeys__actions';
		const rename = button(
			__( 'Rename', 'portier' ),
			/* translators: %s: passkey name */
			sprintf( __( 'Rename passkey “%s”', 'portier' ), passkey.name ),
			() => startRename( li, passkey )
		);
		const remove = button(
			__( 'Delete', 'portier' ),
			/* translators: %s: passkey name */
			sprintf( __( 'Delete passkey “%s”', 'portier' ), passkey.name ),
			() => removePasskey( li, passkey )
		);
		remove.classList.add( 'button-link-delete' );
		actions.append( rename, remove );
		li.append( text, actions );
		return li;
	};

	const button = ( label, accessibleName, onClick ) => {
		const element = document.createElement( 'button' );
		element.type = 'button';
		element.className = 'button button-small';
		element.textContent = label;
		element.setAttribute( 'aria-label', accessibleName );
		element.addEventListener( 'click', onClick );
		return element;
	};

	const startRename = ( li, passkey ) => {
		const form = document.createElement( 'span' );
		form.className = 'portier-passkeys__rename';
		const field = document.createElement( 'input' );
		field.type = 'text';
		field.className = 'regular-text';
		field.maxLength = 60;
		field.value = passkey.name;
		field.setAttribute( 'aria-label', __( 'New name', 'portier' ) );
		const save = button(
			__( 'Save', 'portier' ),
			__( 'Save name', 'portier' ),
			async () => {
				try {
					const data = await apiFetch( {
						path: `${ PATH }/${ passkey.id }${ query }`,
						method: 'POST',
						data: { name: field.value },
					} );
					render( data.passkeys );
					say( __( 'Name saved.', 'portier' ) );
					list.querySelector(
						`[data-id="${ window.CSS.escape( passkey.id ) }"] button`
					)?.focus();
				} catch ( error ) {
					say( error.message, true );
				}
			}
		);
		const cancel = button(
			__( 'Cancel', 'portier' ),
			__( 'Cancel renaming', 'portier' ),
			() => {
				render( rows );
				list.querySelector(
					`[data-id="${ window.CSS.escape( passkey.id ) }"] button`
				)?.focus();
			}
		);
		field.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Enter' ) {
				event.preventDefault(); // Do not submit the profile form.
				save.click();
			} else if ( event.key === 'Escape' ) {
				cancel.click();
			}
		} );
		form.append( field, save, cancel );
		li.querySelector( '.portier-passkeys__actions' ).replaceWith( form );
		field.focus();
		field.select();
	};

	const removePasskey = async ( li, passkey ) => {
		// eslint-disable-next-line no-alert -- A simple, accessible confirmation.
		const sure = window.confirm(
			sprintf(
				/* translators: %s: passkey name */
				__(
					'Delete the passkey “%s”? It can no longer be used to log in.',
					'portier'
				),
				passkey.name
			)
		);
		if ( ! sure ) {
			return;
		}
		const next = li.nextElementSibling || li.previousElementSibling;
		try {
			const data = await apiFetch( {
				path: `${ PATH }/${ passkey.id }${ query }`,
				method: 'DELETE',
			} );
			render( data.passkeys );
			say(
				sprintf(
					/* translators: %s: passkey name */
					__( 'Passkey “%s” deleted.', 'portier' ),
					passkey.name
				)
			);
			const target = next
				? list.querySelector(
						`[data-id="${ window.CSS.escape( next.dataset.id ) }"] button`
					)
				: create;
			( target || status ).focus?.();
		} catch ( error ) {
			say( error.message, true );
		}
	};

	const load = async () => {
		const data = await apiFetch( { path: PATH + query } );
		render( data.passkeys );
	};

	if ( own && add && create ) {
		if ( typeof window.PublicKeyCredential !== 'function' ) {
			add.hidden = true;
			say(
				__(
					'This browser does not support passkeys. Use a current browser to add one.',
					'portier'
				)
			);
		} else {
			add.hidden = false;
			create.addEventListener( 'click', async () => {
				create.disabled = true;
				say( __( 'Waiting for your device…', 'portier' ) );
				try {
					const { options } = await apiFetch( {
						path: `${ PATH }/options`,
						method: 'POST',
					} );
					const credential =
						await window.navigator.credentials.create( {
							publicKey: creationOptions( options ),
						} );
					const data = await apiFetch( {
						path: PATH,
						method: 'POST',
						data: {
							credential: attestationToJson( credential ),
							name: nameField?.value || '',
						},
					} );
					render( data.passkeys );
					if ( nameField ) {
						nameField.value = '';
					}
					say(
						__(
							'Passkey added. You can now log in with it.',
							'portier'
						)
					);
				} catch ( error ) {
					if ( isReauthError( error ) ) {
						askToLogInAgain( error );
						return;
					}
					// NotAllowedError: cancelled; InvalidStateError: already on this device.
					const messages = {
						NotAllowedError: __(
							'Cancelled. No passkey was added.',
							'portier'
						),
						InvalidStateError: __(
							'This device already has a passkey for your account.',
							'portier'
						),
					};
					say(
						messages[ error?.name ] ||
							error?.message ||
							__(
								'The passkey could not be added. Please try again.',
								'portier'
							),
						true
					);
				} finally {
					create.disabled = false;
				}
			} );
		}
	}

	// Rows from the server-rendered list get their buttons.
	load().catch( () => {} );
	return { render };
}

const root = document.querySelector( '[data-portier-passkeys]' );
if ( root ) {
	initProfile( root );
}
