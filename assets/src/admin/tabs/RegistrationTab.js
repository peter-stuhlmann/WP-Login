/**
 * "Registrierung" tab.
 */
import { __ } from '@wordpress/i18n';
import {
	Badge,
	Card,
	Row,
	SelectField,
	Switch,
	TextField,
} from '../components/controls';
import { InfoIcon } from '../components/icons';

export default function RegistrationTab( {
	settings,
	set,
	registration,
	setRegistration,
	meta,
	errors,
} ) {
	const roles = Object.entries( registration.roles )
		.filter( ( [ value ] ) => value !== 'administrator' )
		.map( ( [ value, label ] ) => ( { value, label } ) );

	return (
		<div className="portier-columns">
			<div className="portier-column">
				<Card
					title={ __( 'Registration', 'portier' ) }
					description={ __(
						'Visitors only enter their email address. They receive a link to set their password, which also confirms the address.',
						'portier'
					) }
				>
					{ registration.editable ? (
						<>
							<Row
								label={ __(
									'Allow visitors to create an account',
									'portier'
								) }
							>
								{ ( { labelId } ) => (
									<>
										{ registration.open ? (
											<Badge tone="success">
												{ __( 'Open', 'portier' ) }
											</Badge>
										) : (
											<Badge tone="neutral">
												{ __( 'Closed', 'portier' ) }
											</Badge>
										) }
										<Switch
											checked={ registration.open }
											onChange={ ( value ) =>
												setRegistration( 'open', value )
											}
											labelledBy={ labelId }
										/>
									</>
								) }
							</Row>
							<SelectField
								label={ __(
									'Role for new accounts',
									'portier'
								) }
								value={ registration.default_role }
								options={ roles }
								disabled={ ! registration.open }
								onChange={ ( value ) =>
									setRegistration( 'default_role', value )
								}
								error={ errors.default_role }
							/>
						</>
					) : (
						<p className="portier-note">
							<InfoIcon size={ 18 } />
							{ registration.open
								? __(
										'Registration is enabled for this network. It is managed under Network Admin › Settings.',
										'portier'
									)
								: __(
										'Registration is disabled for this network. It is managed under Network Admin › Settings.',
										'portier'
									) }
						</p>
					) }
					<Row
						label={ __(
							'Ask for consent to the privacy policy',
							'portier'
						) }
						description={
							meta.privacy_policy_url
								? __(
										'Required checkbox with a link to your privacy policy.',
										'portier'
									)
								: __(
										'Only shown once a privacy policy page is set.',
										'portier'
									)
						}
					>
						{ ( { labelId, descriptionId } ) => (
							<Switch
								checked={ settings.require_privacy_consent }
								onChange={ ( value ) =>
									set( 'require_privacy_consent', value )
								}
								labelledBy={ labelId }
								describedBy={ descriptionId }
							/>
						) }
					</Row>
				</Card>
			</div>

			<div className="portier-column">
				<Card
					title={ __( 'Passwords', 'portier' ) }
					description={ __(
						'Applies when setting a new password. 12 or more characters are recommended.',
						'portier'
					) }
				>
					<TextField
						type="number"
						label={ __( 'Minimum password length', 'portier' ) }
						suffix={ __( 'characters', 'portier' ) }
						value={ String( settings.min_password_length ) }
						min={ 8 }
						max={ 64 }
						onChange={ ( value ) =>
							set(
								'min_password_length',
								value === '' ? '' : Number( value )
							)
						}
						error={ errors.min_password_length }
					/>
				</Card>
			</div>
		</div>
	);
}
