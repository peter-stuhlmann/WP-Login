/**
 * Form controls of the Portier admin (design: Figma "06 Admin").
 *
 * They are thin wrappers around native elements, so keyboard support, form semantics and
 * screen reader output come from the browser; ARIA is only added where HTML has no equivalent
 * (switch role, error/description wiring).
 */
import { useId } from '@wordpress/element';
import { AlertIcon } from './icons';

/**
 * On/off switch (role="switch"). Labelled by the visible row label.
 *
 * @param {Object}   props
 * @param {boolean}  props.checked
 * @param {Function} props.onChange
 * @param {string}   props.labelledBy    Id of the visible label.
 * @param {string}   [props.describedBy]
 * @param {string}   [props.tone]        "primary" (default) or "success".
 * @param {boolean}  [props.disabled]
 */
export function Switch( {
	checked,
	onChange,
	labelledBy,
	describedBy,
	tone = 'primary',
	disabled = false,
} ) {
	return (
		<button
			type="button"
			role="switch"
			className={ `portier-switch portier-switch--${ tone }` }
			aria-checked={ checked }
			aria-labelledby={ labelledBy }
			aria-describedby={ describedBy }
			disabled={ disabled }
			onClick={ () => onChange( ! checked ) }
		>
			<span className="portier-switch__knob" aria-hidden="true" />
		</button>
	);
}

/**
 * Card row: label and description on the left, control on the right (Figma "Zeile").
 *
 * @param {Object}   props
 * @param {string}   props.label
 * @param {string}   [props.description]
 * @param {Function} props.children      Render prop receiving { labelId, descriptionId }.
 */
export function Row( { label, description, children } ) {
	const id = useId();
	const labelId = `${ id }-label`;
	const descriptionId = description ? `${ id }-description` : undefined;
	return (
		<div className="portier-row">
			<div className="portier-row__text">
				<span className="portier-row__label" id={ labelId }>
					{ label }
				</span>
				{ description && (
					<span
						className="portier-row__description"
						id={ descriptionId }
					>
						{ description }
					</span>
				) }
			</div>
			<div className="portier-row__control">
				{ children( { labelId, descriptionId } ) }
			</div>
		</div>
	);
}

/**
 * Labelled input with optional prefix/suffix, hint and error.
 *
 * @param {Object}   props
 * @param {string}   props.label
 * @param {string}   props.value
 * @param {Function} props.onChange
 * @param {string}   [props.type]
 * @param {string}   [props.prefix]
 * @param {string}   [props.suffix]
 * @param {string}   [props.help]
 * @param {string}   [props.error]
 * @param {number}   [props.min]
 * @param {number}   [props.max]
 * @param {boolean}  [props.mono]
 * @param {boolean}  [props.readOnly]
 * @param {string}   [props.placeholder]
 * @param {string}   [props.autoComplete]
 * @param {Object}   [props.inputProps]   Further attributes for the input (e.g. password manager opt-outs).
 * @param {*}        [props.trailing]     Element after the input inside the field (badge, copy button).
 */
export function TextField( {
	label,
	value,
	onChange,
	type = 'text',
	prefix,
	suffix,
	help,
	error,
	min,
	max,
	mono = false,
	readOnly = false,
	placeholder,
	autoComplete,
	inputProps = {},
	trailing,
} ) {
	const id = useId();
	const describedBy =
		[ help && `${ id }-help`, error && `${ id }-error` ]
			.filter( Boolean )
			.join( ' ' ) || undefined;
	return (
		<div className={ `portier-field${ error ? ' is-invalid' : '' }` }>
			<label className="portier-field__label" htmlFor={ id }>
				{ label }
			</label>
			<div className="portier-input">
				{ prefix && (
					<span className="portier-input__affix" aria-hidden="true">
						{ prefix }
					</span>
				) }
				<input
					id={ id }
					className={ `portier-input__control${
						mono ? ' is-mono' : ''
					}` }
					type={ type }
					inputMode={ type === 'number' ? 'numeric' : undefined }
					value={ value }
					min={ min }
					max={ max }
					readOnly={ readOnly }
					placeholder={ placeholder }
					autoComplete={ autoComplete }
					spellCheck={ mono ? false : undefined }
					aria-invalid={ error ? true : undefined }
					aria-describedby={ describedBy }
					onChange={ ( event ) => onChange( event.target.value ) }
					{ ...inputProps }
				/>
				{ suffix && (
					<span className="portier-input__affix" aria-hidden="true">
						{ suffix }
					</span>
				) }
				{ trailing }
			</div>
			{ help && (
				<p className="portier-field__help" id={ `${ id }-help` }>
					{ help }
				</p>
			) }
			{ error && (
				<p
					className="portier-field__error"
					id={ `${ id }-error` }
					role="alert"
				>
					<AlertIcon size={ 16 } />
					{ error }
				</p>
			) }
		</div>
	);
}

/**
 * Native select with the Figma look.
 *
 * @param {Object}                           props
 * @param {string}                           props.label
 * @param {string|number}                    props.value
 * @param {Array<{value: *, label: string}>} props.options
 * @param {Function}                         props.onChange
 * @param {string}                           [props.help]
 * @param {string}                           [props.error]
 * @param {boolean}                          [props.disabled]
 */
export function SelectField( {
	label,
	value,
	options,
	onChange,
	help,
	error,
	disabled = false,
} ) {
	const id = useId();
	const describedBy =
		[ help && `${ id }-help`, error && `${ id }-error` ]
			.filter( Boolean )
			.join( ' ' ) || undefined;
	return (
		<div className={ `portier-field${ error ? ' is-invalid' : '' }` }>
			<label className="portier-field__label" htmlFor={ id }>
				{ label }
			</label>
			<select
				id={ id }
				className="portier-select"
				value={ value }
				disabled={ disabled }
				aria-invalid={ error ? true : undefined }
				aria-describedby={ describedBy }
				onChange={ ( event ) => onChange( event.target.value ) }
			>
				{ options.map( ( option ) => (
					<option key={ option.value } value={ option.value }>
						{ option.label }
					</option>
				) ) }
			</select>
			{ help && (
				<p className="portier-field__help" id={ `${ id }-help` }>
					{ help }
				</p>
			) }
			{ error && (
				<p
					className="portier-field__error"
					id={ `${ id }-error` }
					role="alert"
				>
					<AlertIcon size={ 16 } />
					{ error }
				</p>
			) }
		</div>
	);
}

/**
 * Radio group with descriptions (native radios in a fieldset).
 *
 * @param {Object}                                                      props
 * @param {string}                                                      props.legend
 * @param {string}                                                      props.value
 * @param {Array<{value: string, label: string, description?: string}>} props.options
 * @param {Function}                                                    props.onChange
 */
export function RadioGroup( { legend, value, options, onChange } ) {
	const name = useId();
	return (
		<fieldset className="portier-radios">
			<legend className="portier-field__label">{ legend }</legend>
			{ options.map( ( option ) => {
				const id = `${ name }-${ option.value }`;
				return (
					<div className="portier-radio" key={ option.value }>
						<input
							type="radio"
							id={ id }
							name={ name }
							value={ option.value }
							checked={ value === option.value }
							aria-describedby={
								option.description ? `${ id }-desc` : undefined
							}
							onChange={ () => onChange( option.value ) }
						/>
						<div>
							<label
								htmlFor={ id }
								className="portier-radio__label"
							>
								{ option.label }
							</label>
							{ option.description && (
								<span
									className="portier-row__description"
									id={ `${ id }-desc` }
								>
									{ option.description }
								</span>
							) }
						</div>
					</div>
				);
			} ) }
		</fieldset>
	);
}

/**
 * Status badge with a coloured dot, e.g. "Deaktiviert".
 *
 * @param {Object} props
 * @param {string} props.children
 * @param {string} [props.tone]   success | warning | info | neutral
 */
export function Badge( { children, tone = 'success' } ) {
	return (
		<span className={ `portier-badge portier-badge--${ tone }` }>
			<span className="portier-badge__dot" aria-hidden="true" />
			{ children }
		</span>
	);
}

/**
 * White card with title and description (Figma "Karte").
 *
 * @param {Object} props
 * @param {string} props.title
 * @param {string} [props.description]
 * @param {*}      props.children
 * @param {string} [props.id]          Anchor for links and buttons that lead to the card.
 */
export function Card( { title, description, children, id: anchor } ) {
	const id = useId();
	return (
		<section className="portier-card" aria-labelledby={ id } id={ anchor }>
			<div className="portier-card__head">
				<h2 className="portier-card__title" id={ id }>
					{ title }
				</h2>
				{ description && (
					<p className="portier-card__description">{ description }</p>
				) }
			</div>
			{ children }
		</section>
	);
}

/**
 * Segmented control (Figma "Segmented"): a radio group that looks like a switcher.
 *
 * @param {Object}                                props
 * @param {string}                                props.legend       Accessible name (visually hidden unless showLegend).
 * @param {string}                                props.value
 * @param {Array<{value: string, label: string}>} props.options
 * @param {Function}                              props.onChange
 * @param {boolean}                               [props.showLegend] Show the legend as a visible label above.
 */
export function Segmented( {
	legend,
	value,
	options,
	onChange,
	showLegend = false,
} ) {
	const name = useId();
	return (
		<fieldset className="portier-segmented-field">
			<legend
				className={
					showLegend ? 'portier-field__label' : 'screen-reader-text'
				}
			>
				{ legend }
			</legend>
			<div className="portier-segmented">
				{ options.map( ( option ) => (
					<label
						key={ option.value }
						htmlFor={ `${ name }-${ option.value }` }
						className={ `portier-segmented__option${
							value === option.value ? ' is-selected' : ''
						}` }
					>
						<input
							type="radio"
							id={ `${ name }-${ option.value }` }
							name={ name }
							value={ option.value }
							checked={ value === option.value }
							onChange={ () => onChange( option.value ) }
						/>
						{ option.label }
					</label>
				) ) }
			</div>
		</fieldset>
	);
}

/**
 * Multiple choice as chips (Figma "Rolle/…"), native checkboxes underneath.
 *
 * @param {Object}                                props
 * @param {string}                                props.legend
 * @param {string[]}                              props.value
 * @param {Array<{value: string, label: string}>} props.options
 * @param {Function}                              props.onChange
 * @param {string}                                [props.error]
 */
export function ChipGroup( { legend, value, options, onChange, error } ) {
	const id = useId();
	const toggle = ( option ) =>
		onChange(
			value.includes( option )
				? value.filter( ( item ) => item !== option )
				: [ ...value, option ]
		);
	return (
		<fieldset
			className="portier-chips"
			aria-describedby={ error ? `${ id }-error` : undefined }
		>
			<legend className="portier-field__label">{ legend }</legend>
			<div className="portier-chips__list">
				{ options.map( ( option ) => {
					const checked = value.includes( option.value );
					return (
						<label
							key={ option.value }
							htmlFor={ `${ id }-${ option.value }` }
							className={ `portier-chip${ checked ? ' is-checked' : '' }` }
						>
							<input
								type="checkbox"
								id={ `${ id }-${ option.value }` }
								checked={ checked }
								onChange={ () => toggle( option.value ) }
							/>
							{ option.label }
						</label>
					);
				} ) }
			</div>
			{ error && (
				<p
					className="portier-field__error"
					id={ `${ id }-error` }
					role="alert"
				>
					<AlertIcon size={ 16 } />
					{ error }
				</p>
			) }
		</fieldset>
	);
}

/**
 * Login method card (Figma "Methode/…"): icon, name, status, control and optional settings.
 *
 * @param {Object}   props
 * @param {*}        props.icon
 * @param {string}   props.title
 * @param {string}   props.description
 * @param {*}        props.badge
 * @param {Function} [props.control]   Render prop receiving { labelId, descriptionId }.
 * @param {*}        [props.children]  Settings shown below while the method is active.
 * @param {boolean}  [props.active]
 * @param {*}        [props.handle]    Drag handle of a sortable list (SortHandle).
 */
export function MethodCard( {
	icon,
	title,
	description,
	badge,
	control,
	children,
	active = false,
	handle = null,
} ) {
	const id = useId();
	return (
		<section
			className={ `portier-method${ active ? ' is-active' : '' }${
				handle ? ' has-handle' : ''
			}` }
			aria-labelledby={ `${ id }-title` }
		>
			<div className="portier-method__row">
				{ handle }
				<span className="portier-method__icon" aria-hidden="true">
					{ icon }
				</span>
				<div className="portier-method__text">
					<div className="portier-method__head">
						<h2
							className="portier-method__title"
							id={ `${ id }-title` }
						>
							{ title }
						</h2>
						{ badge }
					</div>
					<p
						className="portier-method__description"
						id={ `${ id }-desc` }
					>
						{ description }
					</p>
				</div>
				{ control && (
					<div className="portier-method__control">
						{ control( {
							labelId: `${ id }-title`,
							descriptionId: `${ id }-desc`,
						} ) }
					</div>
				) }
			</div>
			{ children && (
				<div className="portier-method__settings">{ children }</div>
			) }
		</section>
	);
}
