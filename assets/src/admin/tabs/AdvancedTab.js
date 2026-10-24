/**
 * "Erweitert" tab: language switcher, audit log and legal links.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Card, Row, SelectField, Switch } from '../components/controls';
import ConfirmDialog from '../components/ConfirmDialog';

export default function AdvancedTab( { settings, set, meta } ) {
	// Switching the audit log off needs a confirmation; switching it on does not.
	const [ confirmOff, setConfirmOff ] = useState( false );
	const pages = [
		{ value: 0, label: __( '— None —', 'portier' ) },
		...meta.pages.map( ( page ) => ( {
			value: page.id,
			label: page.title,
		} ) ),
	];

	return (
		<div className="portier-columns">
			<div className="portier-column">
				<Card title={ __( 'Language', 'portier' ) }>
					<Row
						label={ __( 'Show language switcher', 'portier' ) }
						description={ __(
							'Lets visitors choose any language installed on this site.',
							'portier'
						) }
					>
						{ ( { labelId, descriptionId } ) => (
							<Switch
								checked={ settings.language_switcher }
								onChange={ ( value ) =>
									set( 'language_switcher', value )
								}
								labelledBy={ labelId }
								describedBy={ descriptionId }
							/>
						) }
					</Row>
				</Card>
				<Card
					title={ __( 'Audit log', 'portier' ) }
					description={ sprintf(
						/* translators: %s: directory of the log files, e.g. wp-content/portier-logs/ */
						__(
							'Records setting changes, account security and logins with time, person, IP address and browser – in a file on the server (%s), kept for 12 months.',
							'portier'
						),
						meta.audit_log_dir
					) }
				>
					<Row
						label={ __( 'Record actions', 'portier' ) }
						description={ __(
							'Recommended: shows later who changed what and when.',
							'portier'
						) }
					>
						{ ( { labelId, descriptionId } ) => (
							<Switch
								checked={ settings.audit_log }
								onChange={ ( value ) =>
									value
										? set( 'audit_log', true )
										: setConfirmOff( true )
								}
								labelledBy={ labelId }
								describedBy={ descriptionId }
							/>
						) }
					</Row>
				</Card>
				<ConfirmDialog
					open={ confirmOff }
					title={ __( 'Switch the audit log off?', 'portier' ) }
					confirmLabel={ __( 'Switch off', 'portier' ) }
					cancelLabel={ __( 'Keep recording', 'portier' ) }
					onConfirm={ () => {
						set( 'audit_log', false );
						setConfirmOff( false );
					} }
					onCancel={ () => setConfirmOff( false ) }
				>
					<p>
						{ __(
							'Changes to the settings, account security and logins are then no longer recorded – later nobody can see who changed what.',
							'portier'
						) }
					</p>
					<p>
						{ __(
							'Existing log files stay. Switching off is still recorded as the last entry. It takes effect when you save.',
							'portier'
						) }
					</p>
				</ConfirmDialog>
			</div>
			<div className="portier-column">
				<Card
					title={ __( 'Legal links', 'portier' ) }
					description={ __(
						'Shown in the footer of the login page. The privacy policy is linked automatically.',
						'portier'
					) }
				>
					<SelectField
						label={ __( 'Imprint page', 'portier' ) }
						value={ settings.imprint_page }
						options={ pages }
						onChange={ ( value ) =>
							set( 'imprint_page', Number( value ) )
						}
					/>
					<SelectField
						label={ __( 'Accessibility statement', 'portier' ) }
						value={ settings.accessibility_page }
						options={ pages }
						onChange={ ( value ) =>
							set( 'accessibility_page', Number( value ) )
						}
					/>
					<a
						className="portier-link"
						href={ meta.privacy_settings_url }
					>
						{ meta.privacy_policy_url
							? __( 'Change privacy policy page', 'portier' )
							: __( 'Set a privacy policy page', 'portier' ) }
					</a>
				</Card>
			</div>
		</div>
	);
}
