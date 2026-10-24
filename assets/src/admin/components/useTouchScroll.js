/**
 * Scrolls the preview page like a phone screen: drag with momentum, mouse wheel, keyboard and
 * an iOS-style scroll indicator instead of a scrollbar.
 *
 * The preview frame itself is inert (a picture, not a second copy of the form), so the screen
 * around it receives the input and scrolls the frame's document. Drag distances are divided by
 * the preview scale: the page follows the pointer exactly.
 */
import { useEffect, useRef } from '@wordpress/element';
import { decay, indicator, keyScroll, releaseVelocity } from '../device';

/** How long the indicator stays visible after scrolling stops (ms). */
const INDICATOR_DELAY = 700;

/**
 * @param {Object}                            options
 * @param {{current: HTMLElement|null}}       options.screen    Element that receives the input.
 * @param {{current: HTMLIFrameElement|null}} options.frame     Preview frame.
 * @param {{current: HTMLElement|null}}       options.indicator Scroll indicator element.
 * @param {number}                            options.scale     Current preview scale.
 * @param {{top: number, bottom: number}}     options.insets    Areas the indicator keeps free.
 * @return {Function} Call after each frame load (binds the indicator to the new page).
 */
export default function useTouchScroll( {
	screen,
	frame,
	indicator: bar,
	scale,
	insets,
} ) {
	// Values the long-lived listeners read without being registered again.
	const live = useRef( { scale, insets } );
	live.current = { scale, insets };
	const hideTimer = useRef( 0 );

	const page = () => {
		const doc = frame.current?.contentDocument;
		return doc?.scrollingElement || doc?.documentElement || null;
	};

	const showIndicator = () => {
		const element = page();
		const node = bar.current;
		const box = screen.current;
		if ( ! element || ! node || ! box ) {
			return;
		}
		const { top, bottom } = live.current.insets;
		const track = box.offsetHeight - top - bottom - 8;
		const position = indicator( element, track );
		if ( ! position ) {
			return;
		}
		node.style.height = `${ position.height }px`;
		node.style.transform = `translateY(${ top + 4 + position.top }px)`;
		node.classList.add( 'is-visible' );
		window.clearTimeout( hideTimer.current );
		hideTimer.current = window.setTimeout(
			() => node.classList.remove( 'is-visible' ),
			INDICATOR_DELAY
		);
	};

	useEffect( () => {
		const box = screen.current;
		if ( ! box ) {
			return undefined;
		}
		const reduced = () =>
			!! window.matchMedia?.( '(prefers-reduced-motion: reduce)' )
				?.matches;
		let drag = null;
		let momentum = 0;

		const stopMomentum = () => window.cancelAnimationFrame( momentum );

		const onPointerDown = ( event ) => {
			const element = page();
			if ( ! element || event.button > 0 ) {
				return;
			}
			stopMomentum();
			drag = {
				id: event.pointerId,
				startY: event.clientY,
				startTop: element.scrollTop,
				samples: [ { y: event.clientY, t: event.timeStamp } ],
			};
			box.setPointerCapture?.( event.pointerId );
			box.classList.add( 'is-dragging' );
		};

		const onPointerMove = ( event ) => {
			const element = page();
			if ( ! drag || event.pointerId !== drag.id || ! element ) {
				return;
			}
			element.scrollTop =
				drag.startTop -
				( event.clientY - drag.startY ) / live.current.scale;
			drag.samples = [
				...drag.samples.slice( -9 ),
				{ y: event.clientY, t: event.timeStamp },
			];
		};

		const onPointerUp = ( event ) => {
			if ( ! drag || event.pointerId !== drag.id ) {
				return;
			}
			box.classList.remove( 'is-dragging' );
			// Finger speed in screen pixels → page speed (the page moves the other way).
			let velocity =
				-releaseVelocity( drag.samples ) / live.current.scale;
			drag = null;
			if ( reduced() || ! velocity ) {
				return;
			}
			let last = performance.now();
			const step = ( now ) => {
				const element = page();
				velocity = decay( velocity, now - last );
				if ( ! element || ! velocity ) {
					return;
				}
				const before = element.scrollTop;
				element.scrollTop += velocity * ( now - last );
				last = now;
				// Stop at the ends instead of pushing against them.
				if ( element.scrollTop !== before ) {
					momentum = window.requestAnimationFrame( step );
				}
			};
			momentum = window.requestAnimationFrame( step );
		};

		// Wheel and trackpad scroll the page while it can move; then the admin page scrolls on.
		const onWheel = ( event ) => {
			const element = page();
			if ( ! element ) {
				return;
			}
			stopMomentum();
			const lines = event.deltaMode === 1 ? 16 : 1;
			const before = element.scrollTop;
			element.scrollTop += event.deltaY * lines;
			if ( element.scrollTop !== before ) {
				event.preventDefault();
			}
		};

		const onKeyDown = ( event ) => {
			const element = page();
			if ( ! element || event.altKey || event.ctrlKey || event.metaKey ) {
				return;
			}
			const target = keyScroll(
				event.key,
				element.clientHeight,
				element.scrollHeight - element.clientHeight,
				element.scrollTop
			);
			if ( target === null ) {
				return;
			}
			event.preventDefault();
			stopMomentum();
			element.scrollTo( {
				top: target,
				behavior: reduced() ? 'auto' : 'smooth',
			} );
		};

		box.addEventListener( 'pointerdown', onPointerDown );
		box.addEventListener( 'pointermove', onPointerMove );
		box.addEventListener( 'pointerup', onPointerUp );
		box.addEventListener( 'pointercancel', onPointerUp );
		box.addEventListener( 'wheel', onWheel, { passive: false } );
		box.addEventListener( 'keydown', onKeyDown );
		return () => {
			stopMomentum();
			window.clearTimeout( hideTimer.current );
			box.removeEventListener( 'pointerdown', onPointerDown );
			box.removeEventListener( 'pointermove', onPointerMove );
			box.removeEventListener( 'pointerup', onPointerUp );
			box.removeEventListener( 'pointercancel', onPointerUp );
			box.removeEventListener( 'wheel', onWheel );
			box.removeEventListener( 'keydown', onKeyDown );
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Refs; live values come from `live`.

	// Each load brings a new document: show the indicator whenever it scrolls.
	return () =>
		frame.current?.contentWindow?.addEventListener(
			'scroll',
			showIndicator,
			{ passive: true }
		);
}
