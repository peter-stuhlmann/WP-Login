import { describe, expect, it } from 'vitest';
import { cssErrors, formatCss } from '../../assets/src/admin/css-format';

describe( 'formatCss', () => {
	it( 'puts one declaration per line with tabs and separates rules', () => {
		expect(
			formatCss( 'body{background:red;color:#fff}.a,.b{margin:0}' )
		).toBe(
			'body {\n\tbackground: red;\n\tcolor: #fff\n}\n\n.a,\n.b {\n\tmargin: 0\n}'
		);
	} );

	it( 'keeps nested at-rules, comments and strings intact', () => {
		const css =
			'/* note */@media (min-width:600px){.a::after{content:"{ ; }"}}';
		const formatted = formatCss( css );
		expect( formatted ).toContain( '/* note */' );
		expect( formatted ).toContain( '"{ ; }"' ); // The string is untouched.
		expect( formatted ).toContain(
			'@media (min-width:600px) {\n\t.a::after {'
		);
		expect( formatCss( formatted ) ).toBe( formatted );
	} );

	it( 'leaves empty input alone', () => {
		expect( formatCss( '  ' ) ).toBe( '  ' );
	} );
} );

describe( 'cssErrors', () => {
	it( 'keeps errors only, with 1-based lines, sorted', () => {
		expect(
			cssErrors( [
				{
					severity: 'warning',
					message: 'Use of !important',
					from: { line: 0 },
				},
				{
					severity: 'error',
					message: 'Expected RBRACE at line 5, col 1.',
					from: { line: 4 },
				},
				{
					severity: 'error',
					message: 'Unexpected token',
					from: { line: 1 },
				},
			] )
		).toEqual( [
			{ line: 2, message: 'Unexpected character. (Unexpected token)' },
			{
				line: 5,
				message: 'A closing brace } is missing. (Expected RBRACE)',
			},
		] );
	} );
} );
