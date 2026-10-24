/**
 * Live preview of the login page (Figma "Admin · Design", right column).
 *
 * The real login page is loaded in an iframe with the unsaved design values in the URL; the
 * server applies them for that one request (admins only, nonce-protected). The frame is inert:
 * it is a picture of the page, not a second, focusable copy of it.
 */
import {
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Segmented, SelectField } from './controls';
import { previewUrl } from '../state';
import { CONTRAST_TOKENS } from '../contrast';
import useTouchScroll from './useTouchScroll';
import {
	DEVICES,
	PHONE,
	fitScale,
	frameSize,
	lightChrome,
	statusTime,
	untilNextMinute,
} from '../device';

/**
 * Current time for the status bar; changes at every full minute.
 *
 * @return {string} Formatted time, e.g. "14:05".
 */
function useClock() {
	const locale = document.documentElement.lang;
	const [ now, setNow ] = useState( () => new Date() );
	useEffect( () => {
		const timer = window.setTimeout(
			() => setNow( new Date() ),
			untilNextMinute( now )
		);
		return () => window.clearTimeout( timer );
	}, [ now ] );
	return statusTime( now, locale );
}

/**
 * What the phone itself draws over the page (Figma "Status bar" and "Home indicator", plus
 * the camera cutout): current time, signal, Wi-Fi and battery on top, the bar at the bottom.
 *
 * @param {Object}                          props
 * @param {{top: boolean, bottom: boolean}} props.light White status bar / home indicator.
 */
function PhoneChrome( { light } ) {
	const time = useClock();
	return (
		<div className="portier-phone" aria-hidden="true">
			<div
				className={ `portier-phone__status${
					light.top ? ' is-light' : ''
				}` }
			>
				<span className="portier-phone__time">{ time }</span>
				<span className="portier-phone__island" />
				<span className="portier-phone__icons">
					<svg width="18" height="12" viewBox="0 0 18 12">
						<rect x="0" y="8" width="3" height="4" rx="1" />
						<rect x="5" y="5.5" width="3" height="6.5" rx="1" />
						<rect x="10" y="3" width="3" height="9" rx="1" />
						<rect x="15" y="0" width="3" height="12" rx="1" />
					</svg>
					{ /* Wi-Fi with full signal: a sector and two arcs around one centre, ±45°. */ }
					<svg width="16" height="12" viewBox="0 0 16 12">
						<path
							d="M8 11.2 5.88 9.08a3 3 0 0 1 4.24 0Z"
							stroke="currentColor"
							strokeLinejoin="round"
						/>
						<path
							d="M3.9 7.1a5.8 5.8 0 0 1 8.2 0M1.21 4.41a9.6 9.6 0 0 1 13.58 0"
							fill="none"
							stroke="currentColor"
							strokeWidth="2.2"
							strokeLinecap="round"
						/>
					</svg>
					<svg width="27" height="13" viewBox="0 0 27 13">
						<rect
							x=".5"
							y=".5"
							width="23"
							height="12"
							rx="3.5"
							fill="none"
							stroke="currentColor"
							strokeOpacity=".4"
						/>
						<rect x="2" y="2" width="20" height="9" rx="2" />
						<path
							d="M25 4.5v4c.8-.3 1.3-1.1 1.3-2s-.5-1.7-1.3-2Z"
							fillOpacity=".45"
						/>
					</svg>
				</span>
			</div>
			<span
				className={ `portier-phone__home${
					light.bottom ? ' is-light' : ''
				}` }
			/>
		</div>
	);
}

/**
 * @param {Object}   props
 * @param {string}   props.base     Preview URL from the REST payload (contains the nonce).
 * @param {Object}   props.settings Draft settings.
 * @param {boolean}  props.dirty    Whether there are unsaved changes.
 * @param {Function} props.onTokens Receives the page's effective design tokens after each load.
 * @param {string[]} props.keys     Settings the preview may apply (from the server).
 */
export default function Preview( { base, settings, dirty, onTokens, keys } ) {
	const [ device, setDevice ] = useState( 'mobile' );
	const [ state, setState ] = useState( 'login' );
	const [ src, setSrc ] = useState( () =>
		previewUrl( base, settings, state, keys )
	);
	const [ loading, setLoading ] = useState( true );
	const [ width, setWidth ] = useState( 0 );
	const [ light, setLight ] = useState( { top: false, bottom: false } );
	const stage = useRef( null );
	const frame = useRef( null );
	const screen = useRef( null );
	const scrollBar = useRef( null );

	// The effective tokens of the real page feed the contrast check.
	const readTokens = () => {
		const doc = frame.current?.contentDocument;
		if ( ! doc || ! onTokens ) {
			return;
		}
		const style = doc.defaultView.getComputedStyle( doc.documentElement );
		const tokens = {};
		for ( const name of CONTRAST_TOKENS ) {
			tokens[ name ] = style
				.getPropertyValue( `--portier-color-${ name }` )
				.trim();
		}
		tokens.radius = style
			.getPropertyValue( '--portier-radius-field' )
			.trim();
		onTokens( tokens );
	};

	// On the phone the page gets the status bar and home indicator as safe-area insets, and
	// the status bar takes the colour that is readable on the page's top area.
	const applyDevice = () => {
		const doc = frame.current?.contentDocument;
		if ( ! doc?.documentElement ) {
			return;
		}
		const root = doc.documentElement;
		if ( device === 'mobile' ) {
			root.style.setProperty(
				'--portier-safe-top',
				`${ PHONE.insetTop }px`
			);
			root.style.setProperty(
				'--portier-safe-bottom',
				`${ PHONE.insetBottom }px`
			);
		} else {
			root.style.removeProperty( '--portier-safe-top' );
			root.style.removeProperty( '--portier-safe-bottom' );
		}
		// Phones and tablets have no scrollbar: the indicator appears while scrolling.
		let style = doc.getElementById( 'portier-preview-device' );
		if ( ! style ) {
			style = doc.createElement( 'style' );
			style.id = 'portier-preview-device';
			doc.head.append( style );
		}
		style.textContent =
			device === 'desktop'
				? ''
				: 'html{scrollbar-width:none}html::-webkit-scrollbar{display:none}';
		setLight(
			lightChrome( {
				template: root.dataset.portierTemplate,
				scheme: root.dataset.portierScheme,
				systemDark: doc.defaultView?.matchMedia?.(
					'(prefers-color-scheme: dark)'
				).matches,
			} )
		);
	};
	useEffect( applyDevice, [ device ] );

	// Unsaved custom CSS goes into the loaded page directly (no reload, any length).
	const css = settings.custom_css || '';
	const sendCss = () =>
		frame.current?.contentWindow?.postMessage(
			{ type: 'portier:preview-css', css },
			window.location.origin
		);
	useEffect( () => {
		sendCss();
		// Custom CSS may change colour tokens: read them again once it is applied.
		const reread = window.setTimeout( () => readTokens(), 150 );
		const ready = ( event ) => {
			if (
				event.origin === window.location.origin &&
				event.source === frame.current?.contentWindow &&
				event.data?.type === 'portier:preview-ready'
			) {
				sendCss();
			}
		};
		window.addEventListener( 'message', ready );
		return () => {
			window.clearTimeout( reread );
			window.removeEventListener( 'message', ready );
		};
	}, [ css ] ); // eslint-disable-line react-hooks/exhaustive-deps -- Re-run only when the CSS changes.

	// Reload shortly after the last change (typing in a text field should not reload per key).
	const next = previewUrl( base, settings, state, keys );
	useEffect( () => {
		const timer = window.setTimeout( () => {
			if ( next !== src ) {
				setLoading( true );
				setSrc( next );
			}
		}, 350 );
		return () => window.clearTimeout( timer );
	}, [ next, src ] );

	// Scale the device to the available width. Measured before the first paint (layout
	// effect), so the device appears at its final size instead of growing into it.
	useLayoutEffect( () => {
		const element = stage.current;
		if ( ! element ) {
			return undefined;
		}
		const style = window.getComputedStyle( element );
		setWidth(
			element.clientWidth -
				parseFloat( style.paddingLeft ) -
				parseFloat( style.paddingRight )
		);
		if ( ! window.ResizeObserver ) {
			return undefined;
		}
		const observer = new window.ResizeObserver( ( [ entry ] ) =>
			setWidth( entry.contentRect.width )
		);
		observer.observe( element );
		return () => observer.disconnect();
	}, [] );

	const size = DEVICES[ device ];
	const outer = frameSize( device );
	const mobile = device === 'mobile';
	// The whole device is drawn at full size and scaled as one piece, so frame, rounded
	// screen corners and the page inside stay in proportion at every size. Fit width and
	// height: the whole device stays visible next to the settings.
	const scale = fitScale(
		outer,
		width ? width - 32 : 0,
		Math.max( 360, window.innerHeight - 360 )
	);
	const bindScroll = useTouchScroll( {
		screen,
		frame,
		indicator: scrollBar,
		scale,
		insets: mobile
			? { top: PHONE.insetTop, bottom: PHONE.insetBottom }
			: { top: 0, bottom: 0 },
	} );
	const states = [
		{ value: 'login', label: __( 'Start', 'portier' ) },
		...( settings.login_steps === 'two'
			? [ { value: 'password', label: __( 'Password', 'portier' ) } ]
			: [] ),
		{ value: 'error', label: __( 'Error', 'portier' ) },
		{
			value: '2fa',
			label: __( 'Two-factor authentication', 'portier' ),
		},
		{ value: 'lostpassword', label: __( 'Forgot password', 'portier' ) },
		{ value: 'register', label: __( 'Registration', 'portier' ) },
	];

	return (
		<section
			className="portier-preview"
			aria-labelledby="portier-preview-title"
		>
			<h2 id="portier-preview-title" className="screen-reader-text">
				{ __( 'Preview', 'portier' ) }
			</h2>
			<div className="portier-preview__toolbar">
				<Segmented
					legend={ __( 'Device', 'portier' ) }
					value={ device }
					onChange={ setDevice }
					options={ [
						{ value: 'mobile', label: __( 'Mobile', 'portier' ) },
						{ value: 'tablet', label: __( 'Tablet', 'portier' ) },
						{ value: 'desktop', label: __( 'Desktop', 'portier' ) },
					] }
				/>
				<div className="portier-preview__state">
					<SelectField
						label={ __( 'Screen', 'portier' ) }
						value={ state }
						options={ states }
						onChange={ setState }
					/>
				</div>
				<p className="portier-preview__status" aria-live="polite">
					<span className="portier-badge portier-badge--success">
						<span
							className="portier-badge__dot"
							aria-hidden="true"
						/>
						{ __( 'Live', 'portier' ) }
					</span>
					{ dirty && (
						<span className="portier-preview__dirty">
							{ __( 'Unsaved changes', 'portier' ) }
						</span>
					) }
				</p>
			</div>
			<div className="portier-preview__stage" ref={ stage }>
				<div
					className={ `portier-preview__fit is-${ device }` }
					style={ {
						width: outer.width * scale,
						height: outer.height * scale,
						borderRadius: mobile ? PHONE.radius * scale : 10,
					} }
				>
					<div
						className={ `portier-preview__device is-${ device }${
							loading ? ' is-loading' : ''
						}` }
						style={ {
							width: outer.width,
							height: outer.height,
							padding: outer.bezel,
							borderRadius: mobile ? PHONE.radius : 10 / scale,
							transform: `scale(${ scale })`,
						} }
					>
						{ /* Scrollable like a phone: drag, wheel or keys (useTouchScroll). */ }
						<div
							className="portier-preview__screen"
							ref={ screen }
							role="region"
							aria-label={ __(
								'Preview – drag or use the arrow keys to scroll',
								'portier'
							) }

							tabIndex={ 0 }
						>
							<iframe
								ref={ frame }
								title={ __(
									'Preview of the login page',
									'portier'
								) }
								src={ src }
								inert=""
								tabIndex={ -1 }
								onLoad={ () => {
									setLoading( false );
									applyDevice();
									bindScroll();
									sendCss();
									readTokens();
								} }
								style={ {
									width: size.width,
									height: size.height,
								} }
							/>
							<span
								ref={ scrollBar }
								className={ `portier-preview__scrollbar${
									light.bottom ? ' is-light' : ''
								}` }
								aria-hidden="true"
							/>
							{ mobile && <PhoneChrome light={ light } /> }
						</div>
					</div>
				</div>
			</div>
		</section>
	);
}
