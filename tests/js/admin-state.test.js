import { describe, expect, it } from 'vitest';
import {
	countChanges,
	groupMethods,
	moveItem,
	orderMethods,
	previewUrl,
	providerStatus,
	tabForErrors,
	urlForTab,
} from '../../assets/src/admin/state';
import { urlPrefix } from '../../assets/src/admin/validation';

const payload = ( settings = {}, registration = {} ) => ( {
	settings: {
		slug: 'login',
		rate_limit_attempts: 5,
		enabled: true,
		...settings,
	},
	registration: { open: false, default_role: 'subscriber', ...registration },
} );

describe( 'countChanges', () => {
	it( 'is zero for identical states and before loading', () => {
		expect( countChanges( payload(), payload() ) ).toBe( 0 );
		expect( countChanges( null, payload() ) ).toBe( 0 );
	} );

	it( 'counts changed settings and registration values', () => {
		const draft = payload(
			{ slug: 'anmelden', enabled: false },
			{ open: true }
		);
		expect( countChanges( payload(), draft ) ).toBe( 3 );
	} );

	it( 'treats "5" from an input and 5 from the server as equal', () => {
		expect(
			countChanges( payload(), payload( { rate_limit_attempts: '5' } ) )
		).toBe( 0 );
	} );
} );

describe( 'tabForErrors', () => {
	it( 'opens the tab that contains the first error', () => {
		expect( tabForErrors( { min_password_length: 'x' } ) ).toBe(
			'registration'
		);
		expect( tabForErrors( { slug: 'x', default_role: 'y' } ) ).toBe(
			'security'
		);
		expect( tabForErrors( {} ) ).toBeNull();
	} );
} );

describe( 'urlForTab', () => {
	it( 'keeps the wp-admin submenu page in the URL', () => {
		const base = 'https://example.org/wp-admin/admin.php?page=portier';
		expect( urlForTab( base, 'registration' ) ).toBe(
			'https://example.org/wp-admin/admin.php?page=portier-registration'
		);
		expect( urlForTab( base, 'unknown' ) ).toBe( base );
	} );
} );

describe( 'urlPrefix', () => {
	it( 'shows the address in front of the slug without the scheme', () => {
		expect( urlPrefix( 'https://example.org/blog/login/', 'login' ) ).toBe(
			'example.org/blog/'
		);
	} );

	it( 'is empty for plain permalinks', () => {
		expect( urlPrefix( 'https://example.org/?portier=1', 'login' ) ).toBe(
			''
		);
	} );
} );

describe( 'social login providers', () => {
	const google = ( values = {} ) => ( {
		enabled: false,
		client_id: '',
		client_secret: '',
		link: 'verified',
		signup: false,
		...values,
	} );

	it( 'counts every changed provider field once', () => {
		const saved = payload( { providers: { google: google() } } );
		const draft = payload( {
			providers: {
				google: google( { enabled: true, client_id: 'abc' } ),
			},
		} );
		expect( countChanges( saved, draft ) ).toBe( 2 );
		expect(
			countChanges(
				saved,
				payload( { providers: { google: google() } } )
			)
		).toBe( 0 );
	} );

	it( 'counts role lists as one setting', () => {
		const saved = payload( { social_blocked_roles: [ 'administrator' ] } );
		expect(
			countChanges(
				saved,
				payload( {
					social_blocked_roles: [ 'administrator', 'editor' ],
				} )
			)
		).toBe( 1 );
		expect(
			countChanges(
				saved,
				payload( { social_blocked_roles: [ 'administrator' ] } )
			)
		).toBe( 0 );
	} );

	it( 'reports the status shown on the badge', () => {
		const meta = { client_id_source: '', client_secret_source: '' };
		expect( providerStatus( meta, google( { enabled: true } ) ) ).toBe(
			'setup'
		);
		expect(
			providerStatus(
				meta,
				google( { client_id: 'a', client_secret: 'b' } )
			)
		).toBe( 'inactive' );
		expect(
			providerStatus(
				{ client_id_source: 'option', client_secret_source: 'option' },
				google( { enabled: true, client_id: 'a' } )
			)
		).toBe( 'active' );
		expect(
			providerStatus(
				{
					client_id_source: 'constant',
					client_secret_source: 'constant',
				},
				google( { enabled: true } )
			)
		).toBe( 'active' );
	} );

	it( 'opens the login methods tab for provider errors', () => {
		expect( tabForErrors( { 'providers.google.client_id': 'x' } ) ).toBe(
			'methods'
		);
		expect( tabForErrors( { social_blocked_roles: 'x' } ) ).toBe(
			'methods'
		);
	} );
} );

describe( 'previewUrl', () => {
	it( 'adds only design values and the screen to the nonce URL', () => {
		const url = new URL(
			previewUrl(
				'https://example.org/login/?portier_preview=abc',
				{
					template: 'journal',
					slug: 'secret',
					design_headline: 'Hi & bye',
					login_steps: 'two',
				},
				'password',
				[ 'template', 'design_headline', 'login_steps' ]
			)
		);
		expect( url.searchParams.get( 'portier_preview' ) ).toBe( 'abc' );
		expect( url.searchParams.get( 'state' ) ).toBe( 'password' );
		expect( url.searchParams.get( 'preview[template]' ) ).toBe( 'journal' );
		expect( url.searchParams.get( 'preview[design_headline]' ) ).toBe(
			'Hi & bye'
		);
		expect( url.searchParams.get( 'preview[login_steps]' ) ).toBe( 'two' );
		expect( url.searchParams.has( 'preview[slug]' ) ).toBe( false );
	} );
} );

describe( 'method order', () => {
	it( 'puts configured ids first and keeps the rest in default order', () => {
		expect(
			orderMethods(
				[ 'passkey', 'google', 'linkedin', 'discord' ],
				[ 'discord', 'gone', 'passkey', 'discord' ]
			)
		).toEqual( [ 'discord', 'passkey', 'google', 'linkedin' ] );
		expect( orderMethods( [ 'a', 'b' ] ) ).toEqual( [ 'a', 'b' ] );
	} );

	it( 'moves an entry and clamps at both ends', () => {
		expect( moveItem( [ 'a', 'b', 'c' ], 0, 2 ) ).toEqual( [
			'b',
			'c',
			'a',
		] );
		expect( moveItem( [ 'a', 'b', 'c' ], 2, -5 ) ).toEqual( [
			'c',
			'a',
			'b',
		] );
		const same = [ 'a', 'b' ];
		expect( moveItem( same, 1, 9 ) ).toBe( same );
	} );

	it( 'groups neighbouring social providers like the login screen', () => {
		const groups = groupMethods( [
			{ id: 'google', type: 'social' },
			{ id: 'passkey', type: 'passkey' },
			{ id: 'linkedin', type: 'social' },
			{ id: 'discord', type: 'social' },
		] );
		expect(
			groups.map( ( group ) => group.map( ( method ) => method.id ) )
		).toEqual( [ [ 'google' ], [ 'passkey' ], [ 'linkedin', 'discord' ] ] );
	} );
} );
