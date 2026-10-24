/**
 * Social login provider (Figma "Methode/Google" with "Google-Konfiguration").
 */
import apiFetch from '@wordpress/api-fetch';
import { useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, MethodCard, Segmented, Switch, TextField } from './controls';
import {
	AlertIcon,
	BrandIcon,
	CheckIcon,
	CopyIcon,
	ExternalIcon,
	InfoIcon,
	PlugIcon,
} from './icons';
import { providerStatus } from '../state';

/**
 * Short description per built-in provider; providers added by code get a generic one.
 *
 * @param {string} id
 * @param {string} label
 * @return {string} Description.
 */
function describe( id, label ) {
	const texts = {
		google: __( 'OpenID Connect with PKCE, state and nonce.', 'portier' ),
		linkedin: __(
			'Sign In with LinkedIn using OpenID Connect.',
			'portier'
		),
		discord: __(
			'OAuth 2.0 with PKCE – only accounts with a verified email address.',
			'portier'
		),
	};
	/* translators: %s: provider name */
	return texts[ id ] || sprintf( __( 'Log in with %s.', 'portier' ), label );
}

/**
 * Where to enter the redirect URI at the provider.
 *
 * @param {string} id
 * @return {string} Hint.
 */
function redirectHint( id ) {
	const hints = {
		google: __(
			'Enter this URI in the Google Cloud Console under “Authorized redirect URIs”.',
			'portier'
		),
		linkedin: __(
			'Enter this URI in the LinkedIn Developer Portal under “Auth” → “Authorized redirect URLs”.',
			'portier'
		),
		discord: __(
			'Enter this URI in the Discord Developer Portal under “OAuth2” → “Redirects”.',
			'portier'
		),
	};
	return (
		hints[ id ] ||
		__( 'Register this URI as redirect URI at the provider.', 'portier' )
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.meta             Provider meta from the REST payload.
 * @param {Object}   props.values           Provider settings (enabled, client_id, client_secret, link, signup).
 * @param {Function} props.onChange         ( key, value ) => void
 * @param {Object}   props.errors           Server errors (keys like "providers.google.client_id").
 * @param {boolean}  props.registrationOpen Whether visitors may register.
 * @param {Function} props.notify           Status message callback.
 * @param {*}        [props.handle]         Drag handle (the providers can be sorted).
 */
export default function ProviderCard( {
	meta,
	values,
	onChange,
	errors,
	registrationOpen,
	notify,
	handle = null,
} ) {
	const status = providerStatus( meta, values );
	// "Test connection": null = not tested, 'busy', or the server's checks.
	const [ test, setTest ] = useState( null );
	const runTest = async () => {
		setTest( 'busy' );
		try {
			setTest(
				await apiFetch( {
					path: `/portier/v1/providers/${ meta.id }/test`,
					method: 'POST',
					data: {
						client_id: values.client_id || '',
						client_secret: values.client_secret || '',
					},
				} )
			);
		} catch ( error ) {
			setTest( {
				ok: false,
				checks: [
					{
						key: 'request',
						status: 'fail',
						message:
							error?.message ||
							__( 'The test could not be run.', 'portier' ),
					},
				],
			} );
		}
	};
	const fieldError = ( key ) => errors[ `providers.${ meta.id }.${ key }` ];
	const hasErrors = !! (
		fieldError( 'client_id' ) || fieldError( 'client_secret' )
	);
	const [ open, setOpen ] = useState( false );
	const expanded = open || hasErrors;
	const panelId = useId();

	const badge = {
		active: <Badge tone="success">{ __( 'Active', 'portier' ) }</Badge>,
		setup: (
			<Badge tone="warning">{ __( 'Setup needed', 'portier' ) }</Badge>
		),
		inactive: <Badge tone="neutral">{ __( 'Inactive', 'portier' ) }</Badge>,
	}[ status ];

	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( meta.redirect_uri );
			notify( __( 'Copied to the clipboard.', 'portier' ) );
		} catch {
			notify(
				__(
					'Copying failed. Please select the text and copy it manually.',
					'portier'
				)
			);
		}
	};
	const fromConfig = ( source ) =>
		source === 'constant' ? (
			<Badge tone="info">{ __( 'in wp-config.php', 'portier' ) }</Badge>
		) : null;

	return (
		<MethodCard
			handle={ handle }
			active={ status === 'active' }
			icon={ <BrandIcon provider={ meta.id } size={ 22 } /> }
			title={ meta.label }
			description={ describe( meta.id, meta.label ) }
			badge={ badge }
			control={ ( { labelId, descriptionId } ) => (
				<div className="portier-method__actions">
					<button
						type="button"
						className="portier-btn portier-btn--ghost"
						aria-expanded={ expanded }
						aria-controls={ expanded ? panelId : undefined }
						onClick={ () => setOpen( ! expanded ) }
					>
						{ expanded
							? __( 'Collapse', 'portier' )
							: __( 'Configure', 'portier' ) }
						<span className="screen-reader-text">
							{ meta.label }
						</span>
					</button>
					<Switch
						checked={ values.enabled }
						onChange={ ( value ) => {
							onChange( 'enabled', value );
							if ( value && status === 'setup' ) {
								setOpen( true ); // Show what is missing right away.
							}
						} }
						labelledBy={ labelId }
						describedBy={ descriptionId }
					/>
				</div>
			) }
		>
			{ expanded && (
				<div className="portier-provider" id={ panelId }>
					<div className="portier-provider__pair">
						<TextField
							label={ __( 'Client ID', 'portier' ) }
							value={
								meta.client_id_source === 'constant'
									? ''
									: values.client_id
							}
							placeholder={
								meta.client_id_source === 'constant'
									? __( 'Set in wp-config.php', 'portier' )
									: undefined
							}
							readOnly={ meta.client_id_source === 'constant' }
							onChange={ ( value ) =>
								onChange( 'client_id', value )
							}
							mono
							autoComplete="off"
							trailing={ fromConfig( meta.client_id_source ) }
							error={ fieldError( 'client_id' ) }
						/>
						<TextField
							label={ __( 'Client secret', 'portier' ) }
							type="password"
							value={ values.client_secret }
							placeholder={
								{
									constant: __(
										'Set in wp-config.php',
										'portier'
									),
									option: __(
										'Saved – leave empty to keep it',
										'portier'
									),
								}[ meta.client_secret_source ]
							}
							readOnly={
								meta.client_secret_source === 'constant'
							}
							onChange={ ( value ) =>
								onChange( 'client_secret', value )
							}
							// Never let the browser fill in the WordPress password here.
							autoComplete="new-password"
							inputProps={ {
								'data-1p-ignore': true,
								'data-lpignore': 'true',
								'data-bwignore': true,
							} }
							trailing={ fromConfig( meta.client_secret_source ) }
							error={ fieldError( 'client_secret' ) }
						/>
					</div>
					<TextField
						label={ __( 'Redirect URI', 'portier' ) }
						value={ meta.redirect_uri }
						readOnly
						mono
						onChange={ () => {} }
						help={ redirectHint( meta.id ) }
						trailing={
							<button
								type="button"
								className="portier-input__button"
								onClick={ copy }
							>
								<CopyIcon size={ 14 } />
								{ __( 'Copy', 'portier' ) }
								<span className="screen-reader-text">
									{ __( 'Redirect URI', 'portier' ) }
								</span>
							</button>
						}
					/>
					<div className="portier-provider__pair">
						<Segmented
							legend={ __( 'Link accounts', 'portier' ) }
							showLegend
							value={ values.link }
							onChange={ ( value ) => onChange( 'link', value ) }
							options={ [
								{
									value: 'verified',
									label: __(
										'Verified email only',
										'portier'
									),
								},
								{
									value: 'never',
									label: __( 'Never', 'portier' ),
								},
							] }
						/>
						<Segmented
							legend={ __( 'New accounts', 'portier' ) }
							showLegend
							value={ values.signup ? 'allow' : 'existing' }
							onChange={ ( value ) =>
								onChange( 'signup', value === 'allow' )
							}
							options={ [
								{
									value: 'allow',
									label: __( 'Allow', 'portier' ),
								},
								{
									value: 'existing',
									label: __( 'Existing only', 'portier' ),
								},
							] }
						/>
					</div>
					{ values.signup && ! registrationOpen && (
						<p className="portier-field__help">
							{ __(
								'Registration is closed, so no new accounts are created until it is opened (tab “Registration”).',
								'portier'
							) }
						</p>
					) }
					<p className="portier-field__help">
						{ sprintf(
							/* translators: %s: constant name prefix, e.g. PORTIER_GOOGLE_ */
							__(
								'Tip: keep the secret out of the database with %sCLIENT_SECRET in wp-config.php.',
								'portier'
							),
							meta.constant_prefix
						) }
					</p>
					<div className="portier-provider__links">
						<button
							type="button"
							className="portier-btn portier-btn--secondary portier-btn--sm"
							onClick={ runTest }
							aria-busy={ test === 'busy' || undefined }
							disabled={ test === 'busy' }
						>
							<PlugIcon size={ 16 } />
							{ test === 'busy'
								? __( 'Testing…', 'portier' )
								: __( 'Test connection', 'portier' ) }
						</button>
						{ meta.console_url && (
							<a
								className="portier-btn portier-btn--secondary portier-btn--sm"
								href={ meta.console_url }
								target="_blank"
								rel="noreferrer"
							>
								<ExternalIcon size={ 16 } />
								{ __( 'Developer console', 'portier' ) }
								<span className="screen-reader-text">
									{ __( '(opens in a new tab)', 'portier' ) }
								</span>
							</a>
						) }
						{ meta.docs_url && (
							<a
								className="portier-btn portier-btn--ghost portier-btn--sm"
								href={ meta.docs_url }
								target="_blank"
								rel="noreferrer"
							>
								<ExternalIcon size={ 16 } />
								{ __( 'Guide', 'portier' ) }
								<span className="screen-reader-text">
									{ __( '(opens in a new tab)', 'portier' ) }
								</span>
							</a>
						) }
					</div>
					<div
						className="portier-provider__test"
						role="status"
						aria-live="polite"
					>
						{ test && test !== 'busy' && (
							<ul
								className={ `portier-test${
									test.ok ? ' is-ok' : ' is-problem'
								}` }
							>
								{ test.checks.map( ( check ) => (
									<li
										key={ check.key }
										className={ `portier-test__row is-${ check.status }` }
									>
										<span
											className="portier-test__icon"
											aria-hidden="true"
										>
											{ {
												ok: <CheckIcon size={ 16 } />,
												info: <InfoIcon size={ 16 } />,
											}[ check.status ] || (
												<AlertIcon size={ 16 } />
											) }
										</span>
										<span>{ check.message }</span>
									</li>
								) ) }
							</ul>
						) }
					</div>
				</div>
			) }
		</MethodCard>
	);
}
