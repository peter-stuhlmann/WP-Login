/**
 * Pure helpers for the admin state (unit tested).
 */

/** Tabs and the wp-admin submenu page that shows each of them. */
export const TABS = [
	{ name: 'methods', page: 'portier' },
	{ name: 'security', page: 'portier-security' },
	{ name: 'design', page: 'portier-design' },
	{ name: 'registration', page: 'portier-registration' },
	{ name: 'advanced', page: 'portier-advanced' },
];

/** Which tab a server-side validation error belongs to. */
const FIELD_TABS = {
	magic_link_minutes: 'methods',
	social_blocked_roles: 'methods',
	design_headline: 'design',
	design_text: 'design',
	two_factor_roles: 'security',
	trust_device_days: 'security',
	slug: 'security',
	rate_limit_attempts: 'security',
	rate_limit_lockout_minutes: 'security',
	rate_limit_account_attempts: 'security',
	min_password_length: 'registration',
	default_role: 'registration',
};

/**
 * Number of settings that differ between the saved and the edited state.
 *
 * @param {Object} saved REST payload as last saved.
 * @param {Object} draft Edited payload.
 * @return {number} Count of changed values.
 */
export function countChanges( saved, draft ) {
	if ( ! saved || ! draft ) {
		return 0;
	}
	let count = countDifferences( saved.settings, draft.settings );
	for ( const key of [ 'open', 'default_role' ] ) {
		if ( draft.registration[ key ] !== saved.registration[ key ] ) {
			count++;
		}
	}
	return count;
}

/**
 * Number of differing values; nested objects (providers) are counted per field.
 *
 * @param {Object} saved
 * @param {Object} draft
 * @return {number} Count.
 */
function countDifferences( saved, draft ) {
	let count = 0;
	for ( const key of Object.keys( draft ) ) {
		const before = saved?.[ key ];
		const after = draft[ key ];
		if ( isPlainObject( after ) ) {
			count += countDifferences(
				isPlainObject( before ) ? before : {},
				after
			);
		} else if ( Array.isArray( after ) ) {
			count +=
				JSON.stringify( after ) === JSON.stringify( before ) ? 0 : 1;
			// eslint-disable-next-line eqeqeq -- "5" from an input equals 5 from the server.
		} else if ( after != before ) {
			count++;
		}
	}
	return count;
}

function isPlainObject( value ) {
	return !! value && typeof value === 'object' && ! Array.isArray( value );
}

/**
 * Status of a social login provider (for its badge).
 *
 * @param {Object} meta   Provider meta (where the credentials come from).
 * @param {Object} values Provider settings as edited.
 * @return {'active'|'setup'|'inactive'} Status.
 */
export function providerStatus( meta, values ) {
	const hasId =
		meta.client_id_source === 'constant' || !! values.client_id?.trim();
	const hasSecret =
		meta.client_secret_source !== '' || !! values.client_secret?.trim();
	if ( ! hasId || ! hasSecret ) {
		return 'setup';
	}
	return values.enabled ? 'active' : 'inactive';
}

/**
 * @param {string} key Setting key, e.g. "slug" or "providers.google.client_id".
 * @return {string|undefined} Tab that shows the setting.
 */
function tabOf( key ) {
	return key.startsWith( 'providers.' ) ? 'methods' : FIELD_TABS[ key ];
}

/**
 * Tab to show for a set of server errors (the first one that contains an error).
 *
 * @param {Object<string,string>} errors
 * @return {string|null} Tab name.
 */
export function tabForErrors( errors ) {
	const keys = Object.keys( errors || {} );
	for ( const tab of TABS ) {
		if ( keys.some( ( key ) => tabOf( key ) === tab.name ) ) {
			return tab.name;
		}
	}
	return null;
}

/**
 * The admin URL for a tab (so reloads and the wp-admin submenu stay in sync).
 *
 * @param {string} href Current location.
 * @param {string} tab  Tab name.
 * @return {string} URL.
 */
export function urlForTab( href, tab ) {
	const url = new URL( href );
	const target = TABS.find( ( entry ) => entry.name === tab ) || TABS[ 0 ];
	url.searchParams.set( 'page', target.page );
	return url.toString();
}

/**
 * URL of the live preview with the unsaved design values.
 *
 * @param {string}   base     Preview URL from the server (with nonce).
 * @param {Object}   settings Draft settings.
 * @param {string}   state    Screen to show (login, password, error, 2fa, …).
 * @param {string[]} keys     Settings the preview may apply – from the server
 *                            (Settings::PREVIEW_KEYS), so the lists cannot drift apart.
 * @return {string} URL.
 */
export function previewUrl( base, settings, state, keys ) {
	const url = new URL( base );
	url.searchParams.set( 'state', state );
	for ( const key of keys ) {
		if ( settings[ key ] !== undefined ) {
			url.searchParams.set(
				`preview[${ key }]`,
				String( settings[ key ] )
			);
		}
	}
	return url.toString();
}

/**
 * Methods below the login form in the configured order – the same rule as the server
 * (src/Frontend/MethodOrder.php): configured ids first, the rest in default order, unknown
 * ids skipped.
 *
 * @param {string[]} ids   Available ids in default order.
 * @param {string[]} order Configured order (setting "method_order").
 * @return {string[]} Ordered ids.
 */
export function orderMethods( ids, order = [] ) {
	const known = order.filter(
		( id, index ) => ids.includes( id ) && order.indexOf( id ) === index
	);
	return [ ...known, ...ids.filter( ( id ) => ! known.includes( id ) ) ];
}

/**
 * Moves one entry of a list (drag & drop or arrow keys).
 *
 * @param {Array}  list
 * @param {number} from Current index.
 * @param {number} to   New index (clamped to the list).
 * @return {Array} New list; the same list when nothing moves.
 */
export function moveItem( list, from, to ) {
	const target = Math.max( 0, Math.min( list.length - 1, to ) );
	if ( from === target || from < 0 || from >= list.length ) {
		return list;
	}
	const next = [ ...list ];
	const [ item ] = next.splice( from, 1 );
	next.splice( target, 0, item );
	return next;
}

/**
 * Groups the ordered methods for the preview: consecutive social providers share a row
 * (like on the login screen), the passkey button stands alone.
 *
 * @param {Array<{id: string, type: string}>} methods
 * @return {Array<Array<{id: string, type: string}>>} Groups.
 */
export function groupMethods( methods ) {
	const groups = [];
	for ( const method of methods ) {
		const last = groups[ groups.length - 1 ];
		if ( method.type === 'social' && last?.[ 0 ].type === 'social' ) {
			last.push( method );
		} else {
			groups.push( [ method ] );
		}
	}
	return groups;
}
