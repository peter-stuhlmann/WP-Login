import { describe, expect, it } from 'vitest';
import {
	DEVICES,
	PHONE,
	fitScale,
	frameSize,
	lightChrome,
	statusTime,
	untilNextMinute,
	keyScroll,
	releaseVelocity,
	decay,
	indicator,
} from '../../assets/src/admin/device';

describe( 'frameSize', () => {
	it( 'adds the phone bezel on both sides (Figma "Phone": 414 × 868)', () => {
		expect( frameSize( 'mobile' ) ).toEqual( {
			width: 414,
			height: 868,
			bezel: PHONE.bezel,
		} );
	} );

	it( 'has no frame for tablet and desktop', () => {
		expect( frameSize( 'desktop' ) ).toEqual( {
			...DEVICES.desktop,
			bezel: 0,
		} );
	} );
} );

describe( 'fitScale', () => {
	const phone = frameSize( 'mobile' );

	it( 'fits width and height and never enlarges', () => {
		expect( fitScale( phone, 2000, 2000 ) ).toBe( 1 );
		expect( fitScale( phone, 207, 2000 ) ).toBe( 0.5 );
		expect( fitScale( phone, 2000, 434 ) ).toBe( 0.5 );
	} );

	it( 'uses a placeholder before the stage is measured', () => {
		expect( fitScale( phone, 0, 800 ) ).toBe( 0.5 );
	} );
} );

describe( 'lightChrome', () => {
	it( 'is white where a template is dark on the phone', () => {
		expect(
			lightChrome( { template: 'studio', scheme: 'light' } )
		).toEqual( { top: true, bottom: false } );
		expect(
			lightChrome( { template: 'enterprise', scheme: 'light' } )
		).toEqual( { top: true, bottom: false } );
		expect(
			lightChrome( { template: 'midnight', scheme: 'dark' } )
		).toEqual( { top: true, bottom: true } );
	} );

	it( 'follows the colour scheme otherwise', () => {
		const light = { top: false, bottom: false };
		const dark = { top: true, bottom: true };
		expect(
			lightChrome( { template: 'aurora', scheme: 'light' } )
		).toEqual( light );
		expect( lightChrome( { template: 'aurora', scheme: 'dark' } ) ).toEqual(
			dark
		);
		expect(
			lightChrome( {
				template: 'aurora',
				scheme: 'auto',
				systemDark: true,
			} )
		).toEqual( dark );
		expect(
			lightChrome( {
				template: 'aurora',
				scheme: 'auto',
				systemDark: false,
			} )
		).toEqual( light );
	} );
} );

describe( 'statusTime', () => {
	it( 'shows hours and minutes like a phone (no leading zero, no AM/PM)', () => {
		const morning = new Date( 2026, 9, 19, 9, 5 );
		const afternoon = new Date( 2026, 9, 19, 14, 41 );
		expect( statusTime( morning, 'de-DE' ) ).toBe( '9:05' );
		expect( statusTime( afternoon, 'de-DE' ) ).toBe( '14:41' );
		expect( statusTime( afternoon, 'en-US' ) ).toBe( '2:41' );
	} );

	it( 'falls back to the default language for unknown tags', () => {
		expect(
			statusTime( new Date( 2026, 9, 19, 9, 5 ), 'not a tag!' )
		).toMatch( /^\d{1,2}:05$/ );
	} );
} );

describe( 'untilNextMinute', () => {
	it( 'waits for the next full minute', () => {
		expect(
			untilNextMinute( new Date( 2026, 9, 19, 9, 5, 45, 500 ) )
		).toBe( 14500 );
		expect( untilNextMinute( new Date( 2026, 9, 19, 9, 5, 0, 0 ) ) ).toBe(
			60000
		);
	} );
} );

describe( 'keyScroll', () => {
	it( 'scrolls by line, page and to both ends, within the page', () => {
		expect( keyScroll( 'ArrowDown', 800, 1000, 100 ) ).toBe( 140 );
		expect( keyScroll( 'ArrowUp', 800, 1000, 20 ) ).toBe( 0 );
		expect( keyScroll( 'PageDown', 800, 1000, 100 ) ).toBe( 820 );
		expect( keyScroll( ' ', 800, 1000, 900 ) ).toBe( 1000 );
		expect( keyScroll( 'Home', 800, 1000, 500 ) ).toBe( 0 );
		expect( keyScroll( 'End', 800, 1000, 0 ) ).toBe( 1000 );
	} );

	it( 'ignores other keys (Tab keeps moving the focus)', () => {
		expect( keyScroll( 'Tab', 800, 1000, 0 ) ).toBeNull();
		expect( keyScroll( 'a', 800, 1000, 0 ) ).toBeNull();
	} );
} );

describe( 'releaseVelocity', () => {
	it( 'measures the speed of the last 100 ms of the drag', () => {
		const samples = [
			{ y: 0, t: 0 },
			{ y: 10, t: 400 },
			{ y: 40, t: 450 },
			{ y: 110, t: 500 },
		];
		expect( releaseVelocity( samples ) ).toBe( 1 );
	} );

	it( 'is zero for a tap or a finger that stopped', () => {
		expect( releaseVelocity( [ { y: 5, t: 0 } ] ) ).toBe( 0 );
		expect(
			releaseVelocity( [
				{ y: 0, t: 0 },
				{ y: 50, t: 50 },
				{ y: 50, t: 400 },
			] )
		).toBe( 0 );
	} );
} );

describe( 'decay', () => {
	it( 'slows down per frame and stops when invisible', () => {
		expect( decay( 2, 16 ) ).toBeCloseTo( 1.9 );
		expect( decay( 2, 32 ) ).toBeCloseTo( 1.805 );
		expect( decay( 0.02, 16 ) ).toBe( 0 );
	} );
} );

describe( 'indicator', () => {
	it( 'sizes and places the bar like the visible part of the page', () => {
		const page = { scrollHeight: 1600, clientHeight: 800 };
		expect( indicator( { ...page, scrollTop: 0 }, 400 ) ).toEqual( {
			top: 0,
			height: 200,
		} );
		expect( indicator( { ...page, scrollTop: 800 }, 400 ) ).toEqual( {
			top: 200,
			height: 200,
		} );
	} );

	it( 'keeps a minimum size and hides on pages that fit', () => {
		expect(
			indicator(
				{ scrollTop: 0, scrollHeight: 20000, clientHeight: 800 },
				400
			).height
		).toBe( 36 );
		expect(
			indicator(
				{ scrollTop: 0, scrollHeight: 800, clientHeight: 800 },
				400
			)
		).toBeNull();
	} );
} );
