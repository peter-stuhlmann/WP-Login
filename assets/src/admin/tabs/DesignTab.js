/**
 * "Design" tab (Figma "Admin · Design"): template, appearance and texts on the left, live
 * preview on the right.
 */
import { useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Card,
	Segmented,
	SelectField,
	TextField,
} from '../components/controls';
import Preview from '../components/Preview';
import BackgroundCard from '../components/BackgroundCard';
import CssEditor from '../components/CssEditor';
import TemplatePicker from '../components/TemplatePicker';
import {
	ColorField,
	ContrastCheck,
	RadiusField,
} from '../components/DesignControls';

/** Anchor of the colour card ("own design" leads there). */
const COLORS_CARD = 'portier-design-colors';

/**
 * @param {Object}   props
 * @param {Object}   props.settings Draft settings.
 * @param {Function} props.set      ( key, value ) => void
 * @param {Object}   props.meta     REST meta (templates, preview_url).
 * @param {Object}   props.errors   Server errors.
 * @param {boolean}  props.dirty    Unsaved changes exist.
 */
export default function DesignTab( { settings, set, meta, errors, dirty } ) {
	// Effective tokens of the preview: placeholders and the contrast check.
	const [ tokens, setTokens ] = useState( null );
	const cssId = useId();
	const current =
		meta.templates.find(
			( template ) => template.id === settings.template
		) || meta.templates[ 0 ];

	return (
		<div className="portier-design">
			<div className="portier-design__settings">
				<Card
					title={ __( 'Template', 'portier' ) }
					description={ __(
						'Eight designs for the login page. Every template meets WCAG 2.2 AA. You see changes on the right at once.',
						'portier'
					) }
				>
					<TemplatePicker
						templates={ meta.templates }
						value={ current.id }
						onChange={ ( value ) => set( 'template', value ) }
						customize={ COLORS_CARD }
					/>
					<p className="portier-field__help">
						{ current.description }
					</p>
				</Card>

				<BackgroundCard
					settings={ settings }
					set={ set }
					images={ meta.images }
					dark={ current.dark }
					errors={ errors }
				/>

				<Card
					id={ COLORS_CARD }
					title={ __( 'Colours & shape', 'portier' ) }
					description={
						current.dark === 'both'
							? __(
									'Empty fields use the template. With Aurora, own colours apply to the light look; dark mode keeps its tested colours.',
									'portier'
								)
							: __( 'Empty fields use the template.', 'portier' )
					}
				>
					<div className="portier-design__colors">
						<ColorField
							label={ __( 'Primary', 'portier' ) }
							value={ settings.custom_primary }
							fallback={ tokens?.primary }
							onChange={ ( value ) =>
								set( 'custom_primary', value )
							}
							error={ errors.custom_primary }
						/>
						<ColorField
							label={ __( 'Accent', 'portier' ) }
							value={ settings.custom_accent }
							fallback={ tokens?.accent }
							onChange={ ( value ) =>
								set( 'custom_accent', value )
							}
							error={ errors.custom_accent }
						/>
						<ColorField
							label={ __( 'Background', 'portier' ) }
							value={ settings.custom_background }
							fallback={ tokens?.bg }
							onChange={ ( value ) =>
								set( 'custom_background', value )
							}
							error={ errors.custom_background }
						/>
					</div>
					<RadiusField
						value={ Number( settings.custom_radius ) }
						fallback={ parseInt( tokens?.radius, 10 ) || 14 }
						onChange={ ( value ) => set( 'custom_radius', value ) }
					/>
					<SelectField
						label={ __( 'Font', 'portier' ) }
						value={ settings.custom_font }
						onChange={ ( value ) => set( 'custom_font', value ) }
						options={ [
							{
								value: '',
								label: sprintf(
									/* translators: %s: font name */
									__( 'Template default (%s)', 'portier' ),
									meta.fonts[ current.font ] || current.font
								),
							},
							...Object.entries( meta.fonts ).map(
								( [ value, label ] ) => ( { value, label } )
							),
						] }
						help={ __(
							'All fonts are hosted on this site – no request to Google or other font services.',
							'portier'
						) }
					/>
					<Segmented
						legend={ __( 'Colour scheme', 'portier' ) }
						showLegend
						value={
							current.dark === 'both'
								? settings.color_scheme
								: current.dark
						}
						onChange={ ( value ) => set( 'color_scheme', value ) }
						options={
							current.dark === 'both'
								? [
										{
											value: 'light',
											label: __( 'Light', 'portier' ),
										},
										{
											value: 'dark',
											label: __( 'Dark', 'portier' ),
										},
										{
											value: 'auto',
											label: __(
												'Auto (system)',
												'portier'
											),
										},
									]
								: [
										{
											value: current.dark,
											label:
												current.dark === 'dark'
													? __( 'Dark', 'portier' )
													: __( 'Light', 'portier' ),
										},
									]
						}
					/>
					<p className="portier-field__help">
						{ current.dark === 'both'
							? __(
									'“Auto” follows the visitor’s system setting.',
									'portier'
								)
							: sprintf(
									/* translators: %s: template name */
									__(
										'%s has one fixed colour scheme. Aurora also offers dark mode.',
										'portier'
									),
									current.label
								) }
					</p>
				</Card>

				{ current.panel && (
					<Card
						title={ __( 'Texts', 'portier' ) }
						description={
							current.id === 'enterprise'
								? __(
										'Shown next to the site name in the header bar.',
										'portier'
									)
								: __(
										'Shown in the large area beside the form.',
										'portier'
									)
						}
					>
						<TextField
							label={
								current.id === 'enterprise'
									? __( 'Label', 'portier' )
									: __( 'Headline', 'portier' )
							}
							value={ settings.design_headline }
							placeholder={ current.defaults.headline }
							onChange={ ( value ) =>
								set( 'design_headline', value )
							}
							help={ __(
								'Leave empty to use the suggestion.',
								'portier'
							) }
							error={ errors.design_headline }
						/>
						{ current.id !== 'enterprise' && (
							<TextField
								label={ __( 'Text', 'portier' ) }
								value={ settings.design_text }
								placeholder={ current.defaults.text }
								onChange={ ( value ) =>
									set( 'design_text', value )
								}
								error={ errors.design_text }
							/>
						) }
					</Card>
				) }

				<Card
					title={ __( 'Accessibility check', 'portier' ) }
					description={ __(
						'Checked live on the preview (WCAG 2.2): 4.5:1 for text, 3:1 for borders and focus.',
						'portier'
					) }
				>
					<ContrastCheck tokens={ tokens } />
				</Card>

				<Card
					title={ __( 'Custom CSS', 'portier' ) }
					description={ __(
						'For administrators who may add unfiltered HTML. Cleaned when saved; the last 10 versions are kept.',
						'portier'
					) }
				>
					<div className="portier-field">
						<label
							className="portier-field__label"
							htmlFor={ cssId }
							id={ `${ cssId }-label` }
						>
							{ __( 'CSS', 'portier' ) }
						</label>
						<CssEditor
							id={ cssId }
							labelId={ `${ cssId }-label` }
							value={ settings.custom_css }
							readOnly={ ! meta.can_edit_css }
							describedBy={ `${ cssId }-help` }
							invalid={ !! errors.custom_css }
							onChange={ ( value ) => set( 'custom_css', value ) }
						/>
						<p
							className="portier-field__help"
							id={ `${ cssId }-help` }
						>
							{ meta.can_edit_css
								? __(
										'Available variables: --portier-color-*, --portier-radius-*, --portier-font, --portier-font-display. Changes appear in the preview while you type.',
										'portier'
									)
								: __(
										'Only administrators who may add unfiltered HTML can change custom CSS.',
										'portier'
									) }
						</p>
						{ errors.custom_css && (
							<p className="portier-field__error" role="alert">
								{ errors.custom_css }
							</p>
						) }
					</div>
					{ meta.can_edit_css && meta.css_revisions.length > 0 && (
						<SelectField
							label={ __(
								'Restore an earlier version',
								'portier'
							) }
							value=""
							onChange={ ( index ) =>
								index !== '' &&
								set(
									'custom_css',
									meta.css_revisions[ Number( index ) ].css
								)
							}
							options={ [
								{
									value: '',
									label: __( 'Choose a version…', 'portier' ),
								},
								...meta.css_revisions.map(
									( revision, index ) => ( {
										value: String( index ),
										label: sprintf(
											/* translators: 1: date and time, 2: author */
											__( '%1$s – %2$s', 'portier' ),
											new Date(
												revision.time * 1000
											).toLocaleString(),
											revision.author
										),
									} )
								),
							] }
							help={ __(
								'The version is loaded into the editor; save to use it.',
								'portier'
							) }
						/>
					) }
				</Card>
			</div>

			<div className="portier-design__preview">
				<Preview
					base={ meta.preview_url }
					settings={ settings }
					dirty={ dirty }
					onTokens={ setTokens }
					keys={ meta.preview_keys }
				/>
			</div>
		</div>
	);
}
