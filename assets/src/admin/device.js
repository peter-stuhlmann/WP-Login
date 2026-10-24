/**
 * Devices of the live preview (Figma "Phone" and the screen sizes of the designs).
 *
 * Plain functions without React so they can be tested on their own.
 */

/** Viewport of each device in CSS pixels. */
export const DEVICES = {
	mobile: { width: 390, height: 844 },
	tablet: { width: 768, height: 1024 },
	desktop: { width: 1440, height: 900 },
};

/**
 * Phone frame (Figma "Phone": 12 px bezel, 56 px corners) and the areas the system draws
 * over the page: status bar on top, home indicator at the bottom. The page receives them as
 * safe-area insets, like on a real phone with `viewport-fit=cover`.
 */
export const PHONE = {
	bezel: 12,
	radius: 56,
	insetTop: 50,
	insetBottom: 34,
};

/** Templates whose top area is dark on phones (status bar in white). */
const DARK_TOP = [ 'studio', 'midnight', 'enterprise' ];

/** Templates whose bottom area is dark on phones (home indicator in white). */
const DARK_BOTTOM = [ 'midnight' ];

/**
 * Outer size of a device including its frame, in unscaled pixels.
 *
 * @param {string} device mobile|tablet|desktop
 * @return {{width: number, height: number, bezel: number}} Size of the frame.
 */
export function frameSize( device ) {
	const size = DEVICES[ device ] || DEVICES.mobile;
	const bezel = device === 'mobile' ? PHONE.bezel : 0;
	return {
		width: size.width + bezel * 2,
		height: size.height + bezel * 2,
		bezel,
	};
}

/**
 * Scale that fits the whole device into the available space (never enlarged).
 *
 * @param {{width: number, height: number}} frame     Outer device size.
 * @param {number}                          width     Available width.
 * @param {number}                          maxHeight Available height.
 * @return {number} Factor between 0 and 1.
 */
export function fitScale( frame, width, maxHeight ) {
	if ( ! width ) {
		return 0.5;
	}
	return Math.max(
		0.1,
		Math.min( 1, width / frame.width, maxHeight / frame.height )
	);
}

/**
 * Whether the status bar (top) and the home indicator (bottom) are drawn light, so they
 * stay visible on the part of the page they lie on.
 *
 * @param {Object}  page
 * @param {string}  page.template   Template id of the loaded page.
 * @param {string}  page.scheme     light|dark|auto.
 * @param {boolean} page.systemDark Whether the system prefers dark (for "auto").
 * @return {{top: boolean, bottom: boolean}} True for white.
 */
export function lightChrome( { template, scheme, systemDark } ) {
	const dark = scheme === 'dark' || ( scheme === 'auto' && !! systemDark );
	return {
		top: dark || DARK_TOP.includes( template ),
		bottom: dark || DARK_BOTTOM.includes( template ),
	};
}

/**
 * Time for the phone's status bar: hours and minutes in the admin's language, without
 * AM/PM (phones leave it out there too), e.g. "9:41" or "14:05".
 *
 * @param {Date}   date
 * @param {string} [locale] BCP 47 language tag, e.g. "de-DE".
 * @return {string} Formatted time.
 */
export function statusTime( date, locale ) {
	let format;
	try {
		format = new Intl.DateTimeFormat( locale || undefined, {
			hour: 'numeric',
			minute: '2-digit',
		} );
	} catch {
		format = new Intl.DateTimeFormat( undefined, {
			hour: 'numeric',
			minute: '2-digit',
		} );
	}
	const parts = format.formatToParts( date );
	const hour = parts.find( ( part ) => part.type === 'hour' )?.value || '';
	const minute =
		parts.find( ( part ) => part.type === 'minute' )?.value || '';
	// No leading zero for the hour, like the status bar of a phone.
	return `${ hour.replace( /^0(?=\d)/, '' ) }:${ minute }`;
}

/**
 * Milliseconds until the next full minute (when the status bar clock changes).
 *
 * @param {Date} date
 * @return {number} Delay in ms.
 */
export function untilNextMinute( date ) {
	return 60000 - ( date.getSeconds() * 1000 + date.getMilliseconds() );
}

/**
 * Scroll distance for a key on the preview screen (like a scrollable page).
 *
 * @param {string} key      KeyboardEvent.key.
 * @param {number} viewport Visible height of the page in px.
 * @param {number} max      Largest possible scroll position.
 * @param {number} current  Current scroll position.
 * @return {number|null} New scroll position, or null when the key does not scroll.
 */
export function keyScroll( key, viewport, max, current ) {
	const page = Math.round( viewport * 0.9 );
	const targets = {
		ArrowDown: current + 40,
		ArrowUp: current - 40,
		PageDown: current + page,
		PageUp: current - page,
		' ': current + page,
		Home: 0,
		End: max,
	};
	if ( ! ( key in targets ) ) {
		return null;
	}
	return Math.min( max, Math.max( 0, targets[ key ] ) );
}

/**
 * Speed of a drag at release, from the last pointer positions (px per ms, positive = down).
 *
 * @param {Array<{y: number, t: number}>} samples Pointer positions with timestamps.
 * @param {number}                        window  Only samples of the last n ms count.
 * @return {number} Velocity of the finger.
 */
export function releaseVelocity( samples, window = 100 ) {
	if ( samples.length < 2 ) {
		return 0;
	}
	const last = samples[ samples.length - 1 ];
	const first =
		samples.find( ( sample ) => last.t - sample.t <= window ) || last;
	const time = last.t - first.t;
	return time > 0 ? ( last.y - first.y ) / time : 0;
}

/**
 * Momentum after release: the speed decays like on a phone (per 16 ms frame: × 0.95).
 *
 * @param {number} velocity Current speed in px per ms.
 * @param {number} elapsed  Time since the last frame in ms.
 * @return {number} New speed; 0 once it is too slow to see.
 */
export function decay( velocity, elapsed ) {
	const next = velocity * Math.pow( 0.95, elapsed / 16 );
	return Math.abs( next ) < 0.02 ? 0 : next;
}

/**
 * Position and size of the scroll indicator (iOS style) inside its track.
 *
 * @param {Object} page
 * @param {number} page.scrollTop
 * @param {number} page.scrollHeight
 * @param {number} page.clientHeight
 * @param {number} track             Height of the track in px.
 * @return {{top: number, height: number}|null} Null when the page does not scroll.
 */
export function indicator( { scrollTop, scrollHeight, clientHeight }, track ) {
	const max = scrollHeight - clientHeight;
	if ( max <= 1 ) {
		return null;
	}
	const height = Math.max( 36, ( clientHeight / scrollHeight ) * track );
	const ratio = Math.min( 1, Math.max( 0, scrollTop / max ) );
	return { top: ratio * ( track - height ), height };
}
