/**
 * Template choice (Figma "Template-Raster"): one card per template with a miniature of its
 * login screen, plus the "own design" card that leads to the colour settings.
 *
 * The cards are a radio group: arrow keys move between templates, the miniatures are
 * decorative and every template is announced with its name and description.
 */
import { __ } from '@wordpress/i18n';
import { CheckIcon, ChevronRightIcon, PaletteIcon } from './icons';

/**
 * Miniature tokens per template, taken from the Figma template tokens: page background,
 * card surface, text, field, field border, button colour and the radii of card, field and
 * button. `layout` switches the special miniatures (Studio's split screen, Midnight's glow,
 * Pure without a card, Bold's hard shadow).
 */
const THUMBS = {
	aurora: {
		bg: 'linear-gradient(135deg,#d8ccff,#c9dbff 50%,#c8f3ea)',
		surface: '#fff',
		text: '#0f0e17',
		field: '#fff',
		border: '#83839a',
		primary: '#15131f',
		radius: [ 28, 14, 14 ],
	},
	pure: {
		layout: 'plain',
		bg: '#fff',
		surface: 'transparent',
		text: '#0a0a0a',
		field: '#fff',
		border: '#8a8a8a',
		primary: '#0a0a0a',
		radius: [ 0, 8, 8 ],
	},
	studio: {
		layout: 'split',
		bg: '#fff',
		panel: 'linear-gradient(135deg,#1e1b4b,#4338ca 55%,#7c3aed)',
		surface: 'transparent',
		text: '#111027',
		field: '#fff',
		border: '#8584a0',
		primary: '#4f46e5',
		radius: [ 0, 9, 9 ],
	},
	midnight: {
		layout: 'glow',
		dark: true,
		bg: '#05060a',
		surface: '#0b0d13',
		text: '#edeff6',
		field: '#080a0f',
		border: '#5f667c',
		primary: '#c8f55a',
		radius: [ 20, 10, 10 ],
	},
	journal: {
		bg: '#f2ece1',
		surface: '#fbf8f2',
		text: '#1d1a15',
		field: '#fffdf9',
		border: '#8a7f6e',
		primary: '#1d1a15',
		radius: [ 6, 4, 4 ],
	},
	bold: {
		layout: 'bold',
		bg: '#ffd84d',
		surface: '#fff',
		text: '#0a0a0a',
		field: '#fff',
		border: '#0a0a0a',
		primary: '#ff5fa2',
		radius: [ 18, 10, 10 ],
	},
	cloud: {
		bg: 'linear-gradient(135deg,#ffe4d6,#f6e6f7 50%,#e2e4ff)',
		surface: '#fff',
		text: '#1e1a2e',
		field: '#faf9fe',
		border: '#8d88a3',
		primary: '#5646d8',
		radius: [ 40, 18, 999 ],
	},
	enterprise: {
		bg: '#edf0f4',
		surface: '#fff',
		text: '#101828',
		field: '#fff',
		border: '#8592a6',
		primary: '#0b57d0',
		radius: [ 10, 6, 6 ],
	},
};

/**
 * Miniature of a template's login screen.
 *
 * Radii are scaled like in Figma: the card gets half of its real radius (at most 12) plus 2,
 * fields and buttons a third (at most 10).
 *
 * @param {Object} props
 * @param {string} props.id Template id.
 */
export function TemplateThumb( { id } ) {
	const thumb = THUMBS[ id ] || THUMBS.aurora;
	const [ card, field, button ] = thumb.radius;
	const style = {
		'--thumb-bg': thumb.bg,
		'--thumb-panel': thumb.panel,
		'--thumb-surface': thumb.surface,
		'--thumb-text': thumb.text,
		'--thumb-field': thumb.field,
		'--thumb-border': thumb.border,
		'--thumb-primary': thumb.primary,
		'--thumb-radius-card': `${ Math.min( card, 12 ) / 2 + 2 }px`,
		'--thumb-radius-field': `${ Math.min( field, 10 ) / 3 }px`,
		'--thumb-radius-button': `${ Math.min( button, 10 ) / 3 }px`,
	};
	return (
		<span
			className={ `portier-thumb is-${ thumb.layout || 'card' }${
				thumb.dark ? ' is-dark' : ''
			}` }
			style={ style }
			aria-hidden="true"
		>
			{ thumb.layout === 'split' && (
				<span className="portier-thumb__panel" />
			) }
			{ thumb.layout === 'glow' && (
				<span className="portier-thumb__glow" />
			) }
			<span className="portier-thumb__card">
				<span className="portier-thumb__title" />
				<span className="portier-thumb__field" />
				<span className="portier-thumb__button" />
			</span>
		</span>
	);
}

/**
 * @param {Object}   props
 * @param {Array}    props.templates Templates from the REST meta (id, label, description).
 * @param {string}   props.value     Chosen template id.
 * @param {Function} props.onChange  Receives the new template id.
 * @param {string}   props.customize Id of the card with the own design settings.
 */
export default function TemplatePicker( {
	templates,
	value,
	onChange,
	customize,
} ) {
	// "Own design" starts from the chosen template: the colour settings below override it.
	const openCustomize = () => {
		const target = document.getElementById( customize );
		if ( ! target ) {
			return;
		}
		const smooth = ! window.matchMedia?.(
			'(prefers-reduced-motion: reduce)'
		)?.matches;
		target.scrollIntoView( {
			behavior: smooth ? 'smooth' : 'auto',
			block: 'start',
		} );
		target.querySelector( 'input[type="text"], select, textarea' )?.focus( {
			preventScroll: true,
		} );
	};

	return (
		<div className="portier-template-picker">
			<fieldset className="portier-templates">
				<legend className="screen-reader-text">
					{ __( 'Template', 'portier' ) }
				</legend>
				{ templates.map( ( template ) => {
					const checked = template.id === value;
					const id = `portier-template-${ template.id }`;
					return (
						<label
							key={ template.id }
							htmlFor={ id }
							className={ `portier-template${
								checked ? ' is-checked' : ''
							}` }
						>
							<input
								type="radio"
								id={ id }
								name="portier-template"
								value={ template.id }
								checked={ checked }
								aria-describedby={ `${ id }-desc` }
								onChange={ () => onChange( template.id ) }
							/>
							<TemplateThumb id={ template.id } />
							<span className="portier-template__label">
								<span className="portier-template__name">
									{ template.label }
								</span>
								{ checked && (
									<span className="portier-template__check">
										<CheckIcon
											size={ 12 }
											strokeWidth={ 3 }
										/>
									</span>
								) }
							</span>
							<span
								className="screen-reader-text"
								id={ `${ id }-desc` }
							>
								{ template.description }
							</span>
						</label>
					);
				} ) }
			</fieldset>
			<button
				type="button"
				className="portier-template-custom"
				onClick={ openCustomize }
			>
				<PaletteIcon size={ 20 } />
				<span className="portier-template-custom__text">
					<span className="portier-template-custom__title">
						{ __( 'Own design', 'portier' ) }
					</span>
					<span className="portier-template-custom__hint">
						{ __(
							'Starts as a copy of the chosen template',
							'portier'
						) }
					</span>
				</span>
				<ChevronRightIcon size={ 18 } />
			</button>
		</div>
	);
}
