/**
 * "Loginarten" tab (Figma "Admin · Loginarten"): list of methods plus a sidebar with a preview
 * of the buttons below the form and the security setting for social login.
 *
 * E-mail & password and the magic link stay on top; passkeys and social providers can be
 * sorted (drag handle or arrow keys) – that is their order below the login form.
 */
import { useEffect, useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Badge,
	Card,
	ChipGroup,
	MethodCard,
	Segmented,
	Switch,
	TextField,
} from '../components/controls';
import {
	BrandIcon,
	FingerprintIcon,
	LockIcon,
	SparklesIcon,
} from '../components/icons';
import ProviderCard from '../components/ProviderCard';
import SortHandle from '../components/SortHandle';
import { groupMethods, moveItem, orderMethods, providerStatus } from '../state';

export default function MethodsTab( {
	settings,
	set,
	errors,
	meta,
	roles,
	registrationOpen,
	notify,
} ) {
	const minutes = Number( settings.magic_link_minutes ) || 0;
	const setProvider = ( id, key, value ) =>
		set( 'providers', {
			...settings.providers,
			[ id ]: { ...settings.providers[ id ], [ key ]: value },
		} );
	const hintId = useId();
	const providers = Object.fromEntries(
		meta.providers.map( ( provider ) => [ provider.id, provider ] )
	);
	const order = orderMethods(
		[ 'passkey', ...meta.providers.map( ( provider ) => provider.id ) ],
		settings.method_order
	);
	const nameOf = ( id ) =>
		id === 'passkey' ? __( 'Passkeys', 'portier' ) : providers[ id ].label;

	// After moving with the keyboard the handle keeps the focus (React moved its element).
	const [ focusId, setFocusId ] = useState( '' );
	useEffect( () => {
		if ( focusId ) {
			document.getElementById( `portier-sort-${ focusId }` )?.focus();
		}
	}, [ focusId, order.join() ] ); // eslint-disable-line react-hooks/exhaustive-deps -- Runs when the order changed.

	const move = ( from, to ) => {
		const next = moveItem( order, from, to );
		if ( next === order ) {
			return;
		}
		const id = order[ from ];
		set( 'method_order', next );
		setFocusId( id );
		notify(
			sprintf(
				/* translators: 1: method name, 2: position, 3: number of methods */
				__( '%1$s is now at position %2$d of %3$d.', 'portier' ),
				nameOf( id ),
				next.indexOf( id ) + 1,
				next.length
			)
		);
	};
	const handleFor = ( id ) => (
		<SortHandle
			id={ id }
			name={ nameOf( id ) }
			index={ order.indexOf( id ) }
			count={ order.length }
			onMove={ move }
			hintId={ hintId }
		/>
	);

	// The preview shows what visitors see: active methods in this order.
	const passkeysOn = settings.passkeys && meta.passkeys_available;
	const visible = order
		.filter( ( id ) =>
			id === 'passkey'
				? passkeysOn
				: providerStatus(
						providers[ id ],
						settings.providers[ id ]
					) === 'active'
		)
		.map( ( id ) => ( {
			id,
			type: id === 'passkey' ? 'passkey' : 'social',
			label: id === 'passkey' ? '' : providers[ id ].label,
		} ) );

	return (
		<div className="portier-methods-layout">
			<div className="portier-methods">
				<div className="portier-methods__intro">
					<h2 className="portier-section-title">
						{ __( 'Login methods', 'portier' ) }
					</h2>
					<p className="portier-card__description">
						{ __(
							'Choose how people can log in. Two-factor authentication applies to every method.',
							'portier'
						) }
					</p>
				</div>

				<MethodCard
					icon={ <LockIcon size={ 22 } /> }
					title={ __( 'Email & password', 'portier' ) }
					description={ __(
						'The classic login. Uses WordPress authentication, so security plugins keep working.',
						'portier'
					) }
					badge={
						<Badge tone="success">
							{ __( 'Always on', 'portier' ) }
						</Badge>
					}
				>
					<div className="portier-method__field portier-method__field--wide">
						<Segmented
							legend={ __( 'Login screen', 'portier' ) }
							showLegend
							value={ settings.login_steps }
							onChange={ ( value ) =>
								set( 'login_steps', value )
							}
							options={ [
								{
									value: 'one',
									label: __( 'One step', 'portier' ),
								},
								{
									value: 'two',
									label: __( 'Two steps', 'portier' ),
								},
							] }
						/>
						<p className="portier-field__help">
							{ settings.login_steps === 'two'
								? __(
										'First the email address, then the password on its own screen – calm and clear. Password managers keep working.',
										'portier'
									)
								: __(
										'Email address and password on the same screen.',
										'portier'
									) }
						</p>
					</div>
				</MethodCard>

				<MethodCard
					active={ settings.magic_link }
					icon={ <SparklesIcon size={ 22 } /> }
					title={ __( 'Magic link', 'portier' ) }
					description={ sprintf(
						/* translators: %d: lifetime of the login link in minutes */
						_n(
							'One-time login link by email, valid for %d minute. Opens a confirmation page, so email scanners cannot log anybody in.',
							'One-time login link by email, valid for %d minutes. Opens a confirmation page, so email scanners cannot log anybody in.',
							minutes,
							'portier'
						),
						minutes
					) }
					badge={
						settings.magic_link ? (
							<Badge tone="success">
								{ __( 'Enabled', 'portier' ) }
							</Badge>
						) : (
							<Badge tone="neutral">
								{ __( 'Off', 'portier' ) }
							</Badge>
						)
					}
					control={ ( { labelId, descriptionId } ) => (
						<Switch
							checked={ settings.magic_link }
							onChange={ ( value ) => set( 'magic_link', value ) }
							labelledBy={ labelId }
							describedBy={ descriptionId }
						/>
					) }
				>
					{ settings.magic_link && (
						<div className="portier-method__field">
							<TextField
								type="number"
								label={ __( 'Link valid for', 'portier' ) }
								suffix={ __( 'minutes', 'portier' ) }
								value={ String( settings.magic_link_minutes ) }
								min={ 5 }
								max={ 60 }
								onChange={ ( value ) =>
									set(
										'magic_link_minutes',
										value === '' ? '' : Number( value )
									)
								}
								help={ __(
									'Between 5 and 60 minutes. Shorter is safer.',
									'portier'
								) }
								error={ errors.magic_link_minutes }
							/>
						</div>
					) }
				</MethodCard>

				<div className="portier-methods__sortable">
					<h3 className="portier-methods__subtitle">
						{ __( 'Below the login form', 'portier' ) }
					</h3>
					<p className="portier-card__description" id={ hintId }>
						{ __(
							'Order of the buttons below the form: drag the handle, or focus it and use the arrow keys.',
							'portier'
						) }
					</p>
					<div className="portier-sortable" data-sort-list>
						{ order.map( ( id ) => (
							<div
								key={ id }
								className="portier-sortable__item"
								data-sort-id={ id }
							>
								{ id === 'passkey' ? (
									<MethodCard
										handle={ handleFor( id ) }
										active={ passkeysOn }
										icon={ <FingerprintIcon size={ 22 } /> }
										title={ __( 'Passkeys', 'portier' ) }
										description={ __(
											'WebAuthn – phishing-resistant, with autofill in the email field. Replaces password and second factor.',
											'portier'
										) }
										badge={
											settings.passkeys ? (
												<Badge tone="info">
													{ __(
														'Recommended',
														'portier'
													) }
												</Badge>
											) : (
												<Badge tone="neutral">
													{ __( 'Off', 'portier' ) }
												</Badge>
											)
										}
										control={ ( {
											labelId,
											descriptionId,
										} ) => (
											<Switch
												checked={ settings.passkeys }
												onChange={ ( value ) =>
													set( 'passkeys', value )
												}
												labelledBy={ labelId }
												describedBy={ descriptionId }
											/>
										) }
									>
										{ settings.passkeys &&
											! meta.passkeys_available && (
												<p className="portier-field__help">
													{ __(
														'Passkeys need a secure connection (HTTPS). They appear on the login page once the site runs on HTTPS.',
														'portier'
													) }
												</p>
											) }
										{ passkeysOn && (
											<p className="portier-field__help">
												{ __(
													'Everybody adds their passkeys in their profile (Users → Profile → Passkeys).',
													'portier'
												) }
											</p>
										) }
									</MethodCard>
								) : (
									<ProviderCard
										handle={ handleFor( id ) }
										meta={ providers[ id ] }
										values={ settings.providers[ id ] }
										onChange={ ( key, value ) =>
											setProvider( id, key, value )
										}
										errors={ errors }
										registrationOpen={ registrationOpen }
										notify={ notify }
									/>
								) }
							</div>
						) ) }
					</div>
				</div>
			</div>

			<aside
				className="portier-methods__aside"
				aria-label={ __( 'Social login overview', 'portier' ) }
			>
				<Card
					title={ __( 'Preview', 'portier' ) }
					description={ __(
						'These buttons appear below the login form – in this order.',
						'portier'
					) }
				>
					{ visible.length ? (
						<div className="portier-preview-methods">
							{ groupMethods( visible ).map( ( group ) =>
								group[ 0 ].type === 'passkey' ? (
									<span
										key="passkey"
										className="portier-preview-social__button"
									>
										<FingerprintIcon size={ 20 } />
										<span>
											{ __(
												'Log in with a passkey',
												'portier'
											) }
										</span>
									</span>
								) : (
									<div
										key={ group[ 0 ].id }
										className={ `portier-preview-social${
											group.length > 1
												? ' is-compact'
												: ''
										}` }
									>
										{ group.map( ( method ) => (
											<span
												key={ method.id }
												className="portier-preview-social__button"
											>
												<BrandIcon
													provider={ method.id }
												/>
												<span
													className={
														group.length > 1
															? 'screen-reader-text'
															: undefined
													}
												>
													{ sprintf(
														/* translators: %s: provider name, e.g. Google */
														__(
															'Continue with %s',
															'portier'
														),
														method.label
													) }
												</span>
											</span>
										) ) }
									</div>
								)
							) }
						</div>
					) : (
						<p className="portier-card__description">
							{ __(
								'No other method is active yet.',
								'portier'
							) }
						</p>
					) }
				</Card>
				<Card
					title={ __( 'Security', 'portier' ) }
					description={ __(
						'A taken-over Google or Discord account must not open your admin. Accounts with these roles always log in with their password (and 2FA).',
						'portier'
					) }
				>
					<ChipGroup
						legend={ __( 'Social login blocked for', 'portier' ) }
						value={ settings.social_blocked_roles }
						onChange={ ( value ) =>
							set( 'social_blocked_roles', value )
						}
						options={ Object.entries( roles ).map(
							( [ value, label ] ) => ( { value, label } )
						) }
						error={ errors.social_blocked_roles }
					/>
				</Card>
			</aside>
		</div>
	);
}
