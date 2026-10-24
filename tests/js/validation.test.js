import { describe, expect, it } from 'vitest';
import {
	normaliseSlug,
	previewUrl,
	validateSlug,
} from '../../assets/src/admin/validation';

describe( 'slug rules (mirror of Settings::validateSlug)', () => {
	it( 'normalises user input', () => {
		expect( normaliseSlug( ' /Mein Login/ ' ) ).toBe( 'mein-login' );
	} );

	it( 'accepts good slugs', () => {
		expect( validateSlug( 'anmelden' ) ).toBeNull();
		expect( validateSlug( 'team-login-2' ) ).toBeNull();
	} );

	it( 'rejects malformed and reserved slugs', () => {
		expect( validateSlug( 'a' ) ).toBe( 'slug_format' );
		expect( validateSlug( '-login' ) ).toBe( 'slug_format' );
		expect( validateSlug( 'log--in' ) ).toBe( 'slug_format' );
		expect( validateSlug( 'wp-admin' ) ).toBe( 'slug_reserved' );
	} );
} );

describe( 'login URL preview', () => {
	it( 'replaces the slug in pretty URLs', () => {
		expect(
			previewUrl( 'https://example.org/login/', 'login', 'anmelden' )
		).toBe( 'https://example.org/anmelden/' );
	} );

	it( 'keeps plain permalink URLs', () => {
		expect(
			previewUrl( 'https://example.org/?portier=1', 'login', 'anmelden' )
		).toBe( 'https://example.org/?portier=1' );
	} );
} );
