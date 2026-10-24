/* global InputEvent */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	carriedHref,
	initCodeActions,
	initCopy,
	initOtp,
} from '../../assets/src/frontend/enhance';

const flush = ( ms = 0 ) =>
	new Promise( ( resolve ) => setTimeout( resolve, ms ) );

beforeEach( () => {
	document.body.innerHTML =
		'<div id="portier-live" aria-live="polite"></div>';
	Object.defineProperty( window.navigator, 'clipboard', {
		value: { writeText: vi.fn().mockResolvedValue( undefined ) },
		configurable: true,
	} );
} );

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'one-time code field', () => {
	const mount = () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<div class="portier-otp" data-portier-otp><input id="code" inputmode="numeric" autocomplete="one-time-code"></div>'
		);
		const wrapper = document.querySelector( '[data-portier-otp]' );
		initOtp( wrapper );
		return { wrapper, input: wrapper.querySelector( 'input' ) };
	};

	const type = ( input, value ) => {
		input.value = value;
		input.dispatchEvent( new InputEvent( 'input' ) );
	};

	it( 'adds six decorative boxes, hidden from assistive technology', () => {
		const { wrapper } = mount();
		const slots = wrapper.querySelector( '.portier-otp__slots' );
		expect( slots.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( slots.querySelectorAll( '.portier-otp__slot' ) ).toHaveLength(
			6
		);
		expect( wrapper.querySelector( 'input' ).id ).toBe( 'code' ); // the real input stays
	} );

	it( 'mirrors digits and keeps only six of them', () => {
		const { wrapper, input } = mount();
		type( input, '12 34-5678' );
		expect( input.value ).toBe( '123456' );
		const boxes = [ ...wrapper.querySelectorAll( '.portier-otp__slot' ) ];
		expect( boxes.map( ( box ) => box.textContent ).join( '' ) ).toBe(
			'123456'
		);
	} );

	it( 'marks the next box as active', () => {
		const { wrapper, input } = mount();
		type( input, '12' );
		const boxes = wrapper.querySelectorAll( '.portier-otp__slot' );
		expect( boxes[ 2 ].classList.contains( 'is-active' ) ).toBe( true );
		expect( boxes[ 1 ].classList.contains( 'is-active' ) ).toBe( false );
	} );

	it( 'is enhanced only once', () => {
		const { wrapper } = mount();
		initOtp( wrapper );
		expect(
			wrapper.querySelectorAll( '.portier-otp__slots' )
		).toHaveLength( 1 );
	} );

	it( 'shows focus on the wrapper', () => {
		const { wrapper, input } = mount();
		input.dispatchEvent( new Event( 'focus' ) );
		expect( wrapper.classList.contains( 'is-focused' ) ).toBe( true );
		input.dispatchEvent( new Event( 'blur' ) );
		expect( wrapper.classList.contains( 'is-focused' ) ).toBe( false );
	} );
} );

describe( 'copy buttons', () => {
	it( 'are revealed, copy their text and announce success', async () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<button type="button" hidden data-portier-copy="ABCD EFGH" data-copied="Copied.">Copy</button>'
		);
		const button = document.querySelector( '[data-portier-copy]' );
		initCopy( button );
		expect( button.hidden ).toBe( false );

		button.click();
		await flush( 60 );
		expect( window.navigator.clipboard.writeText ).toHaveBeenCalledWith(
			'ABCD EFGH'
		);
		expect(
			document.getElementById( 'portier-live' ).textContent
		).toContain( 'Copied.' );
	} );
} );

describe( 'recovery code actions', () => {
	const mount = () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			`<div hidden data-portier-codes="AAAA-BBBB\nCCCC-DDDD" data-filename="codes.txt">
				<button type="button" data-portier-codes-action="copy" data-done="Codes copied.">Copy</button>
				<button type="button" data-portier-codes-action="download">Download</button>
				<button type="button" data-portier-codes-action="print">Print</button>
			</div>`
		);
		const container = document.querySelector( '[data-portier-codes]' );
		initCodeActions( container );
		return container;
	};

	it( 'become visible with JavaScript', () => {
		expect( mount().hidden ).toBe( false );
	} );

	it( 'copy all codes', async () => {
		mount().querySelector( '[data-portier-codes-action="copy"]' ).click();
		await flush();
		expect( window.navigator.clipboard.writeText ).toHaveBeenCalledWith(
			'AAAA-BBBB\nCCCC-DDDD'
		);
	} );

	it( 'download a text file', async () => {
		URL.createObjectURL = vi.fn().mockReturnValue( 'blob:codes' );
		URL.revokeObjectURL = vi.fn();
		const click = vi
			.spyOn( window.HTMLAnchorElement.prototype, 'click' )
			.mockImplementation( function () {
				expect( this.download ).toBe( 'codes.txt' );
			} );
		mount()
			.querySelector( '[data-portier-codes-action="download"]' )
			.click();
		await flush();
		expect( click ).toHaveBeenCalledTimes( 1 );
		expect( URL.revokeObjectURL ).toHaveBeenCalledWith( 'blob:codes' );
	} );

	it( 'print', async () => {
		window.print = vi.fn();
		mount().querySelector( '[data-portier-codes-action="print"]' ).click();
		await flush();
		expect( window.print ).toHaveBeenCalled();
	} );
} );

describe( 'carried values', () => {
	it( 'carries checked checkboxes and several fields', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input id="portier-log" value="lena"><input type="checkbox" id="portier-rememberme" value="1" checked>' +
				'<a href="https://example.org/login/?action=oauth&provider=google" data-portier-carry="portier-rememberme:rememberme portier-log:user_login">G</a>'
		);
		const link = document.querySelector( 'a' );
		const url = new URL( carriedHref( link ) );
		expect( url.searchParams.get( 'rememberme' ) ).toBe( '1' );
		expect( url.searchParams.get( 'user_login' ) ).toBe( 'lena' );
		expect( url.searchParams.get( 'provider' ) ).toBe( 'google' );

		document.getElementById( 'portier-rememberme' ).checked = false;
		link.href = carriedHref( link );
		expect(
			new URL( carriedHref( link ) ).searchParams.has( 'rememberme' )
		).toBe( false );
	} );

	it( 'adds the typed login to the link', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input id="portier-log" value="lena@example.org"><a href="https://example.org/login/?action=magiclink" data-portier-carry="portier-log:user_login">Link</a>'
		);
		const link = document.querySelector( 'a' );
		expect( carriedHref( link ) ).toBe(
			'https://example.org/login/?action=magiclink&user_login=lena%40example.org'
		);
	} );

	it( 'keeps the link unchanged when the field is empty or missing', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<input id="portier-log" value=""><a href="https://example.org/login/" data-portier-carry="portier-log:user_login">Link</a><a id="other" href="https://example.org/x/" data-portier-carry="missing:q">X</a>'
		);
		expect( carriedHref( document.querySelector( 'a' ) ) ).toBe(
			'https://example.org/login/'
		);
		expect( carriedHref( document.getElementById( 'other' ) ) ).toBe(
			'https://example.org/x/'
		);
	} );
} );
