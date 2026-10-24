/**
 * Portier settings screen (design: Figma "06 Admin").
 *
 * State mirrors the REST payload. "Dirty" is derived from the last saved version, so the save
 * buttons, the change counter and the leave-page warning are always consistent.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TabList, TabPanel } from './components/Tabs';
import { ExternalIcon } from './components/icons';
import { countChanges, tabForErrors, TABS, urlForTab } from './state';
import { previewUrl, urlPrefix, validateSlug } from './validation';
import { rememberLayout } from './skeleton';
import SecurityTab from './tabs/SecurityTab';
import RegistrationTab from './tabs/RegistrationTab';
import AdvancedTab from './tabs/AdvancedTab';
import MethodsTab from './tabs/MethodsTab';
import DesignTab from './tabs/DesignTab';

export const SETTINGS_PATH = '/portier/v1/settings';

/**
 * @param {Object} props
 * @param {string} props.initialTab    Tab to open first.
 * @param {string} props.version       Plugin version for the header badge.
 * @param {Object} [props.initialData] Settings payload (loaded before mounting, see index.js).
 * @param {string} [props.loadError]   Message when the settings could not be loaded.
 */
export default function App( {
	initialTab,
	version,
	initialData = null,
	loadError = '',
} ) {
	const [ saved, setSaved ] = useState( initialData );
	const [ draft, setDraft ] = useState( initialData );
	const [ errors, setErrors ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ tab, setTab ] = useState( initialTab );

	const changes = countChanges( saved, draft );

	// Warn before leaving the page with unsaved changes.
	useEffect( () => {
		if ( ! changes ) {
			return undefined;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ changes ] );

	// Remember the real card heights, so the next visit's placeholders match them exactly:
	// once the tab has settled, and again when leaving (settings may have changed the layout).
	useEffect( () => {
		const root = document.getElementById( 'portier-admin-root' );
		const remember = () => rememberLayout( tab, root );
		const timer = window.setTimeout( remember, 800 );
		window.addEventListener( 'pagehide', remember );
		return () => {
			window.clearTimeout( timer );
			window.removeEventListener( 'pagehide', remember );
		};
	}, [ tab ] );

	// Status messages disappear after a while (they stay in the live region long enough to be read).
	useEffect( () => {
		if ( ! status ) {
			return undefined;
		}
		const timer = window.setTimeout( () => setStatus( '' ), 5000 );
		return () => window.clearTimeout( timer );
	}, [ status ] );

	const selectTab = ( name ) => {
		setTab( name );
		window.history.replaceState(
			null,
			'',
			urlForTab( window.location.href, name )
		);
		syncAdminMenu( name );
	};

	if ( loadError || ! draft ) {
		return (
			<div className="portier-admin">
				<div
					className="portier-notice portier-notice--error"
					role="alert"
				>
					{ loadError ||
						__( 'The settings could not be loaded.', 'portier' ) }
				</div>
			</div>
		);
	}

	const { settings, registration, meta } = draft;
	const set = ( key, value ) => {
		// Functional update: several calls in a row (e.g. focus x and y) must not overwrite each other.
		setDraft( ( current ) => ( {
			...current,
			settings: { ...current.settings, [ key ]: value },
		} ) );
		// Nested errors ("providers.google.client_id") belong to their top-level setting.
		setErrors( ( current ) =>
			Object.fromEntries(
				Object.entries( current ).filter(
					( [ errorKey ] ) =>
						errorKey !== key && ! errorKey.startsWith( `${ key }.` )
				)
			)
		);
	};
	const setRegistration = ( key, value ) =>
		setDraft( {
			...draft,
			registration: { ...registration, [ key ]: value },
		} );

	const slugError = validateSlug( settings.slug );
	const loginUrl = previewUrl(
		saved.meta.login_url,
		saved.settings.slug,
		settings.slug
	);

	const save = async () => {
		if ( slugError ) {
			selectTab( 'security' );
			return;
		}
		setSaving( true );
		try {
			const data = await apiFetch( {
				path: SETTINGS_PATH,
				method: 'POST',
				data: {
					settings,
					registration: registration.editable
						? {
								open: registration.open,
								default_role: registration.default_role,
							}
						: {},
				},
			} );
			setSaved( data );
			setDraft( data );
			setErrors( {} );
			setStatus( __( 'Settings saved.', 'portier' ) );
		} catch ( error ) {
			const fieldErrors = error?.data?.errors || {};
			setErrors( fieldErrors );
			const errorTab = tabForErrors( fieldErrors );
			if ( errorTab ) {
				selectTab( errorTab );
			}
			setStatus(
				error.message ||
					__( 'The settings could not be saved.', 'portier' )
			);
		} finally {
			setSaving( false );
		}
	};

	const tabs = [
		{ name: 'methods', title: __( 'Login methods', 'portier' ) },
		{ name: 'security', title: __( 'Security', 'portier' ) },
		{ name: 'design', title: __( 'Design', 'portier' ) },
		{ name: 'registration', title: __( 'Registration', 'portier' ) },
		{ name: 'advanced', title: __( 'Advanced', 'portier' ) },
	];
	const changeLabel = changes
		? sprintf(
				/* translators: %d: number of unsaved changes */
				_n(
					'%d unsaved change',
					'%d unsaved changes',
					changes,
					'portier'
				),
				changes
			)
		: __( 'All changes saved', 'portier' );

	const saveButton = ( className ) => (
		<button
			type="button"
			className={ `portier-btn portier-btn--primary ${ className }` }
			onClick={ save }
			disabled={ ! changes || saving }
			aria-busy={ saving || undefined }
		>
			{ saving
				? __( 'Saving…', 'portier' )
				: __( 'Save changes', 'portier' ) }
		</button>
	);

	return (
		<div className="portier-admin">
			<header className="portier-header">
				<div className="portier-header__bar">
					<div className="portier-header__brand">
						<span className="portier-logo" aria-hidden="true" />
						<h1 className="portier-header__title">
							{ __( 'Portier', 'portier' ) }
						</h1>
						<span className="portier-version">v{ version }</span>
					</div>
					<div className="portier-header__actions">
						<button
							type="button"
							role="switch"
							aria-checked={ settings.enabled }
							className={ `portier-master${ settings.enabled ? ' is-on' : '' }` }
							onClick={ () =>
								set( 'enabled', ! settings.enabled )
							}
						>
							<span
								className="portier-switch portier-switch--success"
								aria-hidden="true"
							>
								<span className="portier-switch__knob" />
							</span>
							<span className="portier-master__label">
								{ settings.enabled
									? __( 'Custom login active', 'portier' )
									: __( 'Custom login inactive', 'portier' ) }
							</span>
						</button>
						<a
							className="portier-btn portier-btn--secondary portier-header__view"
							href={ saved.meta.login_url }
							target="_blank"
							rel="noreferrer"
						>
							<ExternalIcon size={ 18 } />
							<span className="portier-btn__text">
								{ __( 'View login page', 'portier' ) }
							</span>
							<span className="screen-reader-text">
								{ __( '(opens in a new tab)', 'portier' ) }
							</span>
						</a>
						{ saveButton( 'portier-header__save' ) }
					</div>
				</div>
				<TabList
					tabs={ tabs }
					active={ tab }
					onSelect={ selectTab }
					label={ __( 'Portier settings', 'portier' ) }
				/>
			</header>

			<div className="portier-body">
				{ meta.kill_switch && (
					<div
						className="portier-notice portier-notice--warning"
						role="status"
					>
						{ __(
							'PORTIER_DISABLE is set in wp-config.php: Portier is switched off and the default WordPress login is used. Remove the constant to activate Portier again.',
							'portier'
						) }
					</div>
				) }
				{ meta.network_locked?.length > 0 && (
					<div
						className="portier-notice portier-notice--warning"
						role="status"
					>
						{ __(
							'Accounts belong to the whole network: only network administrators can change the security settings (login address on/off, limits, two-factor authentication, login links, social login roles, audit log).',
							'portier'
						) }
					</div>
				) }
				<TabPanel name={ tab }>
					{ tab === 'methods' && (
						<MethodsTab
							settings={ settings }
							set={ set }
							errors={ errors }
							meta={ meta }
							roles={ registration.roles }
							registrationOpen={ registration.open }
							notify={ setStatus }
						/>
					) }
					{ tab === 'security' && (
						<SecurityTab
							settings={ settings }
							set={ set }
							errors={ errors }
							slugError={ slugError }
							loginUrl={ loginUrl }
							urlPrefix={ urlPrefix( loginUrl, settings.slug ) }
							notify={ setStatus }
							roles={ registration.roles }
						/>
					) }
					{ tab === 'design' && (
						<DesignTab
							settings={ settings }
							set={ set }
							meta={ meta }
							errors={ errors }
							dirty={ changes > 0 }
						/>
					) }
					{ tab === 'registration' && (
						<RegistrationTab
							settings={ settings }
							set={ set }
							registration={ registration }
							setRegistration={ setRegistration }
							meta={ meta }
							errors={ errors }
						/>
					) }
					{ tab === 'advanced' && (
						<AdvancedTab
							settings={ settings }
							set={ set }
							meta={ meta }
						/>
					) }
				</TabPanel>
			</div>

			{ /* Mobile: sticky save bar (Figma "Admin · Mobil"). */ }
			<div className="portier-savebar">
				<p className="portier-savebar__text">{ changeLabel }</p>
				{ saveButton( 'portier-savebar__save' ) }
			</div>

			<div
				className="portier-toast-region"
				role="status"
				aria-live="polite"
			>
				{ status && <div className="portier-toast">{ status }</div> }
			</div>
		</div>
	);
}

/**
 * Keeps the highlighted entry of the wp-admin submenu in sync with the active tab.
 *
 * @param {string} tab
 */
function syncAdminMenu( tab ) {
	const page = ( TABS.find( ( entry ) => entry.name === tab ) || TABS[ 0 ] )
		.page;
	document
		.querySelectorAll( '#toplevel_page_portier .wp-submenu li' )
		.forEach( ( item ) => {
			const link = item.querySelector( 'a' );
			const isCurrent =
				!! link &&
				new URL( link.href ).searchParams.get( 'page' ) === page;
			item.classList.toggle( 'current', isCurrent );
			if ( link ) {
				link.classList.toggle( 'current', isCurrent );
				if ( isCurrent ) {
					link.setAttribute( 'aria-current', 'page' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			}
		} );
}
