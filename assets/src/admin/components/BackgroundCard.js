/**
 * "Hintergrundbild" card (Figma "Admin · Design"): image from the media library behind the
 * template background, focus point, overlay transparency, dark-mode image and phones.
 */
import { useId, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Card, Row, Switch } from './controls';

/**
 * Opens the WordPress media modal and returns the chosen image.
 *
 * @param {Function} onSelect Receives { id, url, filename }.
 */
function openMediaLibrary( onSelect ) {
	const media = window.wp?.media;
	if ( ! media ) {
		return;
	}
	const frame = media( {
		title: __( 'Choose a background image', 'portier' ),
		button: { text: __( 'Use this image', 'portier' ) },
		library: { type: 'image' },
		multiple: false,
	} );
	frame.on( 'select', () => {
		const image = frame.state().get( 'selection' ).first().toJSON();
		onSelect( {
			id: image.id,
			url:
				image.sizes?.medium_large?.url ||
				image.sizes?.large?.url ||
				image.url,
			filename: image.filename,
		} );
	} );
	frame.open();
}

/**
 * A labelled range input with value badge.
 *
 * @param {Object}   props
 * @param {string}   props.label
 * @param {number}   props.value
 * @param {Function} props.onChange
 * @param {string}   props.valueText  Spoken value, e.g. "50 %".
 * @param {number}   [props.step]
 * @param {*}        [props.children] Scale labels below the slider.
 */
function Slider( { label, value, onChange, valueText, step = 1, children } ) {
	const id = useId();
	return (
		<div className="portier-field">
			<div className="portier-slider__head">
				<label className="portier-field__label" htmlFor={ id }>
					{ label }
				</label>
				<output htmlFor={ id } className="portier-range__value">
					{ valueText }
				</output>
			</div>
			<input
				id={ id }
				className="portier-slider"
				type="range"
				min={ 0 }
				max={ 100 }
				step={ step }
				value={ value }
				aria-valuetext={ valueText }
				onChange={ ( event ) =>
					onChange( Number( event.target.value ) )
				}
			/>
			{ children }
		</div>
	);
}

/**
 * Image with focus point: drag with the pointer (keyboard users use the two sliders).
 *
 * @param {Object}   props
 * @param {string}   props.url
 * @param {number}   props.x
 * @param {number}   props.y
 * @param {Function} props.onChange ( x, y ) => void
 */
function FocusPicker( { url, x, y, onChange } ) {
	const box = useRef( null );
	const [ dragging, setDragging ] = useState( false );
	const move = ( event ) => {
		const rect = box.current.getBoundingClientRect();
		const clamp = ( value ) =>
			Math.round( Math.min( 100, Math.max( 0, value ) ) );
		onChange(
			clamp( ( ( event.clientX - rect.left ) / rect.width ) * 100 ),
			clamp( ( ( event.clientY - rect.top ) / rect.height ) * 100 )
		);
	};
	return (
		<div
			ref={ box }
			className={ `portier-focus${ dragging ? ' is-dragging' : '' }` }
			onPointerDown={ ( event ) => {
				event.currentTarget.setPointerCapture( event.pointerId );
				setDragging( true );
				move( event );
			} }
			onPointerMove={ ( event ) => dragging && move( event ) }
			onPointerUp={ () => setDragging( false ) }
		>
			<img src={ url } alt="" draggable={ false } />
			<span
				className="portier-focus__dot"
				style={ { left: `${ x }%`, top: `${ y }%` } }
				aria-hidden="true"
			/>
			<span className="portier-focus__hint" aria-hidden="true">
				{ __( 'Drag the focus point', 'portier' ) }
			</span>
		</div>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.settings
 * @param {Function} props.set
 * @param {Object}   props.images   Chosen images from the REST payload ({ bg_image, bg_image_dark }).
 * @param {string}   props.dark     Dark support of the template ("both", "light", "dark").
 * @param {Object}   props.errors
 */
export default function BackgroundCard( {
	settings,
	set,
	images,
	dark,
	errors,
} ) {
	const [ picked, setPicked ] = useState( images || {} );
	const choose = ( key ) =>
		openMediaLibrary( ( image ) => {
			setPicked( ( current ) => ( { ...current, [ key ]: image } ) );
			set( key, image.id );
		} );
	const image = settings.bg_image ? picked.bg_image : null;
	const darkImage = settings.bg_image_dark ? picked.bg_image_dark : null;
	const overlay = Number( settings.bg_overlay );
	const percent = ( value ) =>
		/* translators: %d: percentage */
		sprintf( __( '%d %%', 'portier' ), value );

	return (
		<Card
			title={ __( 'Background image', 'portier' ) }
			description={ __(
				'Lies behind the template background, which becomes an overlay – its transparency decides how much of the image shows.',
				'portier'
			) }
		>
			{ image ? (
				<>
					<FocusPicker
						url={ image.url }
						x={ Number( settings.bg_focus_x ) }
						y={ Number( settings.bg_focus_y ) }
						onChange={ ( x, y ) => {
							set( 'bg_focus_x', x );
							set( 'bg_focus_y', y );
						} }
					/>
					<div className="portier-background__actions">
						<button
							type="button"
							className="portier-btn portier-btn--secondary portier-btn--sm"
							onClick={ () => choose( 'bg_image' ) }
						>
							{ __( 'Change image', 'portier' ) }
						</button>
						<button
							type="button"
							className="portier-btn portier-btn--ghost portier-btn--sm"
							onClick={ () => set( 'bg_image', 0 ) }
						>
							{ __( 'Remove', 'portier' ) }
						</button>
						<span className="portier-background__file">
							{ image.filename }
						</span>
					</div>
					<div className="portier-background__focus">
						<Slider
							label={ __( 'Focus point horizontal', 'portier' ) }
							value={ Number( settings.bg_focus_x ) }
							valueText={ percent(
								Number( settings.bg_focus_x )
							) }
							onChange={ ( value ) => set( 'bg_focus_x', value ) }
						/>
						<Slider
							label={ __( 'Focus point vertical', 'portier' ) }
							value={ Number( settings.bg_focus_y ) }
							valueText={ percent(
								Number( settings.bg_focus_y )
							) }
							onChange={ ( value ) => set( 'bg_focus_y', value ) }
						/>
					</div>
					<Slider
						label={ __( 'Overlay transparency', 'portier' ) }
						value={ overlay }
						step={ 5 }
						valueText={ percent( overlay ) }
						onChange={ ( value ) => set( 'bg_overlay', value ) }
					>
						<div
							className="portier-slider__scale"
							aria-hidden="true"
						>
							<span>
								{ __( '0 % · template only', 'portier' ) }
							</span>
							<span>{ __( 'Default 50 %', 'portier' ) }</span>
							<span>
								{ __( '100 % · image only', 'portier' ) }
							</span>
						</div>
					</Slider>
					{ dark === 'both' && (
						<Row
							label={ __( 'Own image for dark mode', 'portier' ) }
							description={ __(
								'Shown when the page appears dark.',
								'portier'
							) }
						>
							{ ( { labelId, descriptionId } ) => (
								<div className="portier-background__dark">
									{ settings.bg_dark && (
										<button
											type="button"
											className="portier-background__thumb"
											onClick={ () =>
												choose( 'bg_image_dark' )
											}
										>
											{ darkImage ? (
												<img
													src={ darkImage.url }
													alt=""
												/>
											) : null }
											<span className="screen-reader-text">
												{ darkImage
													? __(
															'Change dark-mode image',
															'portier'
														)
													: __(
															'Choose dark-mode image',
															'portier'
														) }
											</span>
											{ ! darkImage && (
												<span aria-hidden="true">
													+
												</span>
											) }
										</button>
									) }
									<Switch
										checked={ !! settings.bg_dark }
										onChange={ ( value ) =>
											set( 'bg_dark', value )
										}
										labelledBy={ labelId }
										describedBy={ descriptionId }
									/>
								</div>
							) }
						</Row>
					) }
					<Row
						label={ __( 'On mobile devices', 'portier' ) }
						description={ __(
							'The image fills the area above the form.',
							'portier'
						) }
					>
						{ ( { labelId, descriptionId } ) => (
							<Switch
								checked={ !! settings.bg_mobile }
								onChange={ ( value ) =>
									set( 'bg_mobile', value )
								}
								labelledBy={ labelId }
								describedBy={ descriptionId }
							/>
						) }
					</Row>
				</>
			) : (
				<div className="portier-background__empty">
					<button
						type="button"
						className="portier-btn portier-btn--secondary"
						onClick={ () => choose( 'bg_image' ) }
					>
						{ __( 'Choose image', 'portier' ) }
					</button>
					<p className="portier-field__help">
						{ __(
							'From the media library. Texts outside the form get glass plates automatically, so they stay readable on any image.',
							'portier'
						) }
					</p>
				</div>
			) }
			{ ( errors.bg_image || errors.bg_image_dark ) && (
				<p className="portier-field__error" role="alert">
					{ errors.bg_image || errors.bg_image_dark }
				</p>
			) }
		</Card>
	);
}
