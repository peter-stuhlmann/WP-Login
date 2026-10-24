/**
 * "Sicherheit" tab: login address, emergency access, brute-force protection, other access paths.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Badge,
	Card,
	ChipGroup,
	RadioGroup,
	Row,
	Segmented,
	SelectField,
	Switch,
	TextField,
} from '../components/controls';
import { CodeBlock } from '../components/CodeBlock';
import { normaliseSlug, slugMessage } from '../validation';

export default function SecurityTab( {
	settings,
	set,
	errors,
	slugError,
	loginUrl,
	urlPrefix,
	notify,
	roles,
} ) {
	return (
		<div className="portier-columns">
			<div className="portier-column">
				<TwoFactorCard
					settings={ settings }
					set={ set }
					errors={ errors }
					roles={ roles }
				/>
				<Card
					title={ __( 'Login address', 'portier' ) }
					description={ __(
						'Your own URL for the login. Not a replacement for real security – but it reduces bot noise.',
						'portier'
					) }
				>
					<TextField
						label={ __( 'URL', 'portier' ) }
						prefix={ urlPrefix }
						value={ settings.slug }
						onChange={ ( value ) =>
							set( 'slug', normaliseSlug( value ) )
						}
						help={
							slugError
								? undefined
								: sprintf(
										/* translators: %s: login URL */
										__( 'Your login page: %s', 'portier' ),
										loginUrl
									)
						}
						error={
							errors.slug ||
							( slugError ? slugMessage( slugError ) : undefined )
						}
					/>
					<RadioGroup
						legend={ __( 'Requests to wp-login.php', 'portier' ) }
						value={ settings.core_login_behavior }
						onChange={ ( value ) =>
							set( 'core_login_behavior', value )
						}
						options={ [
							{
								value: 'redirect',
								label: __(
									'Redirect to the new address',
									'portier'
								),
								description: __(
									'Log out, password-protected posts and privacy requests keep working.',
									'portier'
								),
							},
							{
								value: 'block',
								label: __( 'Show 404', 'portier' ),
								description: __(
									'For sites without public login widgets: forms that post to wp-login.php stop working.',
									'portier'
								),
							},
						] }
					/>
				</Card>

				<Card
					title={ __( 'Emergency access', 'portier' ) }
					description={ __(
						'If you lock yourself out: add this line to wp-config.php and the default login applies again.',
						'portier'
					) }
				>
					<CodeBlock
						code="define( 'PORTIER_DISABLE', true );"
						onCopied={ notify }
					/>
				</Card>
			</div>

			<div className="portier-column">
				<Card
					title={ __( 'Brute-force protection', 'portier' ) }
					description={ __(
						'Progressive delay per IP and per account – no hard lock, so nobody can block other people’s accounts.',
						'portier'
					) }
				>
					<div className="portier-grid-2">
						<TextField
							type="number"
							label={ __(
								'Failed attempts before a pause',
								'portier'
							) }
							value={ String( settings.rate_limit_attempts ) }
							min={ 3 }
							max={ 50 }
							onChange={ ( value ) =>
								set( 'rate_limit_attempts', toNumber( value ) )
							}
							error={ errors.rate_limit_attempts }
						/>
						<TextField
							type="number"
							label={ __( 'First pause', 'portier' ) }
							suffix={ __( 'minutes', 'portier' ) }
							value={ String(
								settings.rate_limit_lockout_minutes
							) }
							min={ 1 }
							max={ 1440 }
							onChange={ ( value ) =>
								set(
									'rate_limit_lockout_minutes',
									toNumber( value )
								)
							}
							error={ errors.rate_limit_lockout_minutes }
						/>
					</div>
					<TextField
						type="number"
						label={ __(
							'Failed attempts per account and hour',
							'portier'
						) }
						help={ __(
							'Slows down attacks from many IP addresses. Each further pause doubles, up to 24 hours per IP.',
							'portier'
						) }
						value={ String( settings.rate_limit_account_attempts ) }
						min={ 5 }
						max={ 500 }
						onChange={ ( value ) =>
							set(
								'rate_limit_account_attempts',
								toNumber( value )
							)
						}
						error={ errors.rate_limit_account_attempts }
					/>
					<SelectField
						label={ __( 'Client IP address', 'portier' ) }
						help={ __(
							'Only change this if your site runs behind a proxy or CDN. Untrusted headers can be forged.',
							'portier'
						) }
						value={ settings.trusted_proxy_header }
						onChange={ ( value ) =>
							set( 'trusted_proxy_header', value )
						}
						options={ [
							{
								value: '',
								label: __(
									'Direct connection (REMOTE_ADDR)',
									'portier'
								),
							},
							{
								value: 'HTTP_X_FORWARDED_FOR',
								label: 'X-Forwarded-For',
							},
							{
								value: 'HTTP_CF_CONNECTING_IP',
								label: 'CF-Connecting-IP (Cloudflare)',
							},
							{ value: 'HTTP_X_REAL_IP', label: 'X-Real-IP' },
						] }
					/>
				</Card>

				<Card
					title={ __( 'Secure other access paths', 'portier' ) }
					description={ __(
						'Close the ways around the login screen.',
						'portier'
					) }
				>
					<Row
						label={ __( 'XML-RPC', 'portier' ) }
						description={ __(
							'Not needed by most sites. Required by the WordPress mobile app and Jetpack.',
							'portier'
						) }
					>
						{ ( { labelId, descriptionId } ) => (
							<>
								{ settings.disable_xmlrpc ? (
									<Badge tone="success">
										{ __( 'Disabled', 'portier' ) }
									</Badge>
								) : (
									<Badge tone="warning">
										{ __( 'Enabled', 'portier' ) }
									</Badge>
								) }
								<Switch
									checked={ ! settings.disable_xmlrpc }
									onChange={ ( on ) =>
										set( 'disable_xmlrpc', ! on )
									}
									labelledBy={ labelId }
									describedBy={ descriptionId }
								/>
							</>
						) }
					</Row>
				</Card>
			</div>
		</div>
	);
}

function toNumber( value ) {
	return value === '' ? '' : Number( value );
}

/**
 * Two-factor authentication policy (Figma "Karte/Zwei-Faktor-Authentifizierung").
 *
 * @param {Object}                 props
 * @param {Object}                 props.settings
 * @param {Function}               props.set
 * @param {Object}                 props.errors
 * @param {Object<string, string>} props.roles    Role slug => translated name.
 */
function TwoFactorCard( { settings, set, errors, roles } ) {
	const mode = settings.two_factor_mode;
	// Days shown in the description and restored when the switch is turned back on.
	const trustDays = Number( settings.trust_device_days ) || 30;
	return (
		<Card
			title={ __( 'Two-factor authentication', 'portier' ) }
			description={ __(
				'Codes from an authenticator app (TOTP). Users set it up themselves in their profile; required roles are guided through the setup at their next login. A passkey counts as second factor automatically.',
				'portier'
			) }
		>
			<Segmented
				legend={ __( 'Two-factor authentication', 'portier' ) }
				value={ mode }
				onChange={ ( value ) => set( 'two_factor_mode', value ) }
				options={ [
					{ value: 'off', label: __( 'Off', 'portier' ) },
					{ value: 'optional', label: __( 'Optional', 'portier' ) },
					{
						value: 'required',
						label: __( 'Required for roles', 'portier' ),
					},
				] }
			/>
			{ mode === 'required' && (
				<ChipGroup
					legend={ __( 'Required for', 'portier' ) }
					value={ settings.two_factor_roles }
					onChange={ ( value ) => set( 'two_factor_roles', value ) }
					options={ Object.entries( roles ).map(
						( [ value, label ] ) => ( {
							value,
							label,
						} )
					) }
					error={ errors.two_factor_roles }
				/>
			) }
			{ mode !== 'off' && (
				<>
					<Row
						label={ __( 'Trust devices', 'portier' ) }
						description={ sprintf(
							/* translators: %d: number of days */
							_n(
								'Offer “Trust this device for %d day” after entering a code.',
								'Offer “Trust this device for %d days” after entering a code.',
								trustDays,
								'portier'
							),
							trustDays
						) }
					>
						{ ( { labelId, descriptionId } ) => (
							<Switch
								checked={
									Number( settings.trust_device_days ) > 0
								}
								onChange={ ( on ) =>
									set(
										'trust_device_days',
										on ? trustDays : 0
									)
								}
								labelledBy={ labelId }
								describedBy={ descriptionId }
							/>
						) }
					</Row>
					<Row
						label={ __( 'Recovery codes', 'portier' ) }
						description={ __(
							'10 one-time codes are created during setup, in case the phone gets lost.',
							'portier'
						) }
					>
						{ () => (
							<Badge tone="success">
								{ __( 'Always on', 'portier' ) }
							</Badge>
						) }
					</Row>
				</>
			) }
		</Card>
	);
}
