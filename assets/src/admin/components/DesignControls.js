/**
 * Controls of the "Farben & Form", "Barrierefreiheits-Check" and "Eigenes CSS" cards.
 */
import { useId } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { contrastChecks, toHex } from '../contrast';
import { AlertIcon, CheckIcon } from './icons';

/**
 * Colour input: native colour picker plus hex text field. Empty = template colour.
 *
 * @param {Object}   props
 * @param {string}   props.label
 * @param {string}   props.value    Custom colour or ''.
 * @param {string}   props.fallback Effective template colour (placeholder).
 * @param {Function} props.onChange
 * @param {string}   [props.error]
 */
export function ColorField( { label, value, fallback, onChange, error } ) {
	const id = useId();
	const shown = value || toHex( fallback ) || '#000000';
	return (
		<div className={ `portier-field${ error ? ' is-invalid' : '' }` }>
			<label className="portier-field__label" htmlFor={ id }>
				{ label }
			</label>
			<div className="portier-color">
				<input
					type="color"
					className="portier-color__swatch"
					value={ shown }
					aria-label={ sprintf(
						/* translators: %s: colour name, e.g. Primary */
						__( 'Choose colour: %s', 'portier' ),
						label
					) }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>
				<input
					id={ id }
					type="text"
					className="portier-color__hex"
					value={ value }
					placeholder={ toHex( fallback ) }
					spellCheck={ false }
					autoComplete="off"
					maxLength={ 7 }
					aria-invalid={ error ? true : undefined }
					aria-describedby={ error ? `${ id }-error` : undefined }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>
				{ value && (
					<button
						type="button"
						className="portier-btn portier-btn--ghost portier-btn--sm"
						onClick={ () => onChange( '' ) }
					>
						{ __( 'Default', 'portier' ) }
						<span className="screen-reader-text">{ label }</span>
					</button>
				) }
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
		</div>
	);
}

/**
 * Corner radius slider (Figma "Eckenradius"). -1 = template radius.
 *
 * @param {Object}   props
 * @param {number}   props.value
 * @param {number}   props.fallback Template radius in px.
 * @param {Function} props.onChange
 */
export function RadiusField( { value, fallback, onChange } ) {
	const id = useId();
	const current = value >= 0 ? value : fallback;
	return (
		<div className="portier-field">
			<label className="portier-field__label" htmlFor={ id }>
				{ __( 'Corner radius', 'portier' ) }
			</label>
			<div className="portier-range">
				<input
					id={ id }
					type="range"
					min={ 0 }
					max={ 32 }
					step={ 1 }
					value={ current }
					aria-valuetext={ sprintf(
						/* translators: %d: radius in pixels */
						__( '%d pixels', 'portier' ),
						current
					) }
					onChange={ ( event ) =>
						onChange( Number( event.target.value ) )
					}
				/>
				<output htmlFor={ id } className="portier-range__value">
					{ current }
				</output>
				{ value >= 0 && (
					<button
						type="button"
						className="portier-btn portier-btn--ghost portier-btn--sm"
						onClick={ () => onChange( -1 ) }
					>
						{ __( 'Default', 'portier' ) }
						<span className="screen-reader-text">
							{ __( 'Corner radius', 'portier' ) }
						</span>
					</button>
				) }
			</div>
		</div>
	);
}

const LABELS = () => ( {
	text: __( 'Text on the card', 'portier' ),
	muted: __( 'Secondary text', 'portier' ),
	accent: __( 'Accent / links', 'portier' ),
	border: __( 'Field border', 'portier' ),
	focus: __( 'Focus ring', 'portier' ),
	button: __( 'Button text', 'portier' ),
	error: __( 'Error messages', 'portier' ),
} );

/**
 * Live contrast check of the preview (Figma "Barrierefreiheits-Check").
 *
 * @param {Object}      props
 * @param {Object|null} props.tokens Effective tokens of the preview, null while loading.
 */
export function ContrastCheck( { tokens } ) {
	const labels = LABELS();
	if ( ! tokens ) {
		// While the preview loads: the final layout with empty values, so the card keeps its
		// height and nothing below it moves when the results arrive.
		return (
			<>
				<p
					className="portier-notice portier-notice--pending"
					role="status"
				>
					{ __( 'Checking…', 'portier' ) }
				</p>
				<ul className="portier-contrast" aria-hidden="true">
					{ Object.keys( labels ).map( ( key ) => (
						<li
							key={ key }
							className="portier-contrast__row is-pending"
						>
							<span className="portier-contrast__icon" />
							<span className="portier-contrast__label">
								{ labels[ key ] }
							</span>
							<span className="portier-contrast__ratio">–</span>
							<span className="portier-contrast__level">
								{ ' ' }
							</span>
						</li>
					) ) }
				</ul>
			</>
		);
	}
	const checks = contrastChecks( tokens );
	const failed = checks.filter( ( check ) => check.level === 'fail' );
	return (
		<>
			<p
				className={ `portier-notice portier-notice--${
					failed.length ? 'warning' : 'success'
				}` }
				role="status"
			>
				{ failed.length
					? sprintf(
							/* translators: %d: number of failed contrast checks */
							_n(
								'%d contrast is too low for WCAG 2.2 AA. Please adjust the colours.',
								'%d contrasts are too low for WCAG 2.2 AA. Please adjust the colours.',
								failed.length,
								'portier'
							),
							failed.length
						)
					: __( 'All contrasts meet WCAG 2.2 AA.', 'portier' ) }
			</p>
			<ul className="portier-contrast">
				{ checks.map( ( check ) => (
					<li
						key={ check.key }
						className={ `portier-contrast__row is-${ check.level.toLowerCase() }` }
					>
						<span
							className="portier-contrast__icon"
							aria-hidden="true"
						>
							{ check.level === 'fail' ? (
								<AlertIcon size={ 16 } />
							) : (
								<CheckIcon size={ 16 } />
							) }
						</span>
						<span className="portier-contrast__label">
							{ labels[ check.key ] }
						</span>
						<span className="portier-contrast__ratio">
							{ check.ratio.toFixed( 1 ) }:1
						</span>
						<span className="portier-contrast__level">
							{ check.level === 'fail'
								? sprintf(
										/* translators: %s: required contrast, e.g. 4.5 */
										__( 'min. %s:1', 'portier' ),
										check.min
									)
								: check.level }
						</span>
					</li>
				) ) }
			</ul>
		</>
	);
}
