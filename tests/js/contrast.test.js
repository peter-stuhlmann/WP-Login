import { describe, expect, it } from 'vitest';
import {
	composite,
	contrastChecks,
	parseColor,
	ratio,
	toHex,
} from '../../assets/src/admin/contrast';

describe( 'parseColor', () => {
	it( 'reads hex and both rgb syntaxes', () => {
		expect( parseColor( '#fff' ) ).toEqual( {
			r: 255,
			g: 255,
			b: 255,
			a: 1,
		} );
		expect( parseColor( ' #6230E0 ' ) ).toEqual( {
			r: 98,
			g: 48,
			b: 224,
			a: 1,
		} );
		expect( parseColor( 'rgb(255 255 255 / 72%)' ) ).toEqual( {
			r: 255,
			g: 255,
			b: 255,
			a: 0.72,
		} );
		expect( parseColor( 'rgba(20, 20, 30, 0.7)' ).a ).toBe( 0.7 );
		expect( parseColor( 'var(--x)' ) ).toBeNull();
	} );
} );

describe( 'ratio', () => {
	it( 'matches the WCAG reference values', () => {
		const white = parseColor( '#ffffff' );
		expect( ratio( parseColor( '#000000' ), white ) ).toBeCloseTo( 21, 1 );
		expect( ratio( parseColor( '#767676' ), white ) ).toBeCloseTo(
			4.54,
			2
		);
	} );

	it( 'composites semi-transparent colours first', () => {
		const glass = composite(
			parseColor( 'rgb(255 255 255 / 50%)' ),
			parseColor( '#000' )
		);
		expect( glass ).toEqual( { r: 128, g: 128, b: 128, a: 1 } );
	} );
} );

describe( 'contrastChecks', () => {
	const aurora = {
		bg: '#f4f3fa',
		glass: 'rgb(255 255 255 / 72%)',
		field: '#fff',
		text: '#0f0e17',
		muted: '#4e4e63',
		accent: '#6230e0',
		border: '#83839a',
		focus: '#6d3aed',
		primary: '#15131f',
		'on-primary': '#fff',
		error: '#be1e3a',
	};

	it( 'passes the Aurora tokens', () => {
		const checks = contrastChecks( aurora );
		expect( checks ).toHaveLength( 7 );
		expect( checks.every( ( check ) => check.level !== 'fail' ) ).toBe(
			true
		);
		expect( checks.find( ( check ) => check.key === 'text' ).level ).toBe(
			'AAA'
		);
	} );

	it( 'flags a pale accent and uses 3:1 for borders', () => {
		const checks = contrastChecks( {
			...aurora,
			accent: '#ffe14d',
			border: '#e5e5e5',
		} );
		const byKey = Object.fromEntries(
			checks.map( ( check ) => [ check.key, check ] )
		);
		expect( byKey.accent.level ).toBe( 'fail' );
		expect( byKey.accent.min ).toBe( 4.5 );
		expect( byKey.border.level ).toBe( 'fail' );
		expect( byKey.border.min ).toBe( 3 );
	} );

	it( 'skips tokens that are missing', () => {
		expect( contrastChecks( { bg: '#fff', text: '#000' } ) ).toHaveLength(
			1
		);
	} );
} );

describe( 'toHex', () => {
	it( 'turns token values into colour input values', () => {
		expect( toHex( 'rgb(255 255 255 / 72%)' ) ).toBe( '#ffffff' );
		expect( toHex( '#abc' ) ).toBe( '#aabbcc' );
		expect( toHex( '' ) ).toBe( '' );
	} );
} );
