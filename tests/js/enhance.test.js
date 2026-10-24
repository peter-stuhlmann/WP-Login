/* global KeyboardEvent, HTMLFormElement, Element */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	clearParts,
	enhance,
	handleResponse,
	nameParts,
	setBusy,
	submitForm,
	swap,
	syncBack,
} from '../../assets/src/frontend/enhance';

const passwordField = `
	<div id="portier-card">
		<h1 id="portier-title" tabindex="-1">Log in</h1>
		<form data-portier-form action="/login/">
			<input id="portier-pwd" type="password" data-portier-password aria-describedby="portier-pwd-hint">
			<p id="portier-pwd-hint">Hint</p>
			<button type="button" aria-controls="portier-pwd" aria-pressed="false" data-portier-reveal hidden
				data-label-show="Show password" data-label-hide="Hide password">
				<span class="screen-reader-text">Show password</span>
			</button>
			<p id="portier-pwd-caps" hidden>Caps Lock is on.</p>
			<button type="submit" data-portier-submit data-loading-label="Logging in…">
				<span class="portier-button__label">Log in</span>
			</button>
		</form>
	</div>
	<div id="portier-live" aria-live="polite"></div>`;

beforeEach( () => {
	document.body.innerHTML = passwordField;
	window.matchMedia = vi.fn().mockReturnValue( { matches: true } ); // reduced motion: no view transition
} );

afterEach( () => {
	vi.restoreAllMocks();
	vi.useRealTimers();
} );

describe( 'show password toggle', () => {
	it( 'is revealed by the script and toggles type, aria-pressed and its label', () => {
		enhance( document );
		const button = document.querySelector( '[data-portier-reveal]' );
		const input = document.getElementById( 'portier-pwd' );
		expect( button.hidden ).toBe( false );

		button.click();
		expect( input.type ).toBe( 'text' );
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( button.textContent.trim() ).toBe( 'Hide password' );

		button.click();
		expect( input.type ).toBe( 'password' );
		expect( button.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
	} );

	it( 'is wired only once even if enhance runs again', () => {
		enhance( document );
		enhance( document );
		document.querySelector( '[data-portier-reveal]' ).click();
		expect( document.getElementById( 'portier-pwd' ).type ).toBe( 'text' );
	} );
} );

describe( 'caps lock hint', () => {
	it( 'shows the hint and links it to the field while caps lock is on', () => {
		enhance( document );
		const input = document.getElementById( 'portier-pwd' );
		const hint = document.getElementById( 'portier-pwd-caps' );

		const on = new KeyboardEvent( 'keydown', { key: 'A' } );
		on.getModifierState = () => true;
		input.dispatchEvent( on );
		expect( hint.hidden ).toBe( false );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe(
			'portier-pwd-hint portier-pwd-caps'
		);

		const off = new KeyboardEvent( 'keyup', { key: 'a' } );
		off.getModifierState = () => false;
		input.dispatchEvent( off );
		expect( hint.hidden ).toBe( true );
		expect( input.getAttribute( 'aria-describedby' ) ).toBe(
			'portier-pwd-hint'
		);
	} );
} );

describe( 'busy state', () => {
	it( 'keeps the button focusable, sets aria-busy and announces the loading label', () => {
		vi.useFakeTimers();
		const button = document.querySelector( '[data-portier-submit]' );
		setBusy( button, true );
		expect( button.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( button.disabled ).toBe( false );
		expect( button.textContent.trim() ).toBe( 'Logging in…' );
		vi.runAllTimers();
		expect( document.getElementById( 'portier-live' ).textContent ).toBe(
			'Logging in…'
		);

		setBusy( button, false );
		expect( button.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( button.textContent.trim() ).toBe( 'Log in' );
	} );
} );

describe( 'swapping screens', () => {
	it( 'replaces the card, updates the title and focuses the error summary', async () => {
		await swap( {
			html: '<div id="portier-errors" tabindex="-1">Error</div><h1 id="portier-title" tabindex="-1">X</h1>',
			title: 'New',
			focus: 'errors',
		} );
		expect( document.title ).toBe( 'New' );
		expect( document.activeElement.id ).toBe( 'portier-errors' );
	} );

	it( 'focuses the heading for a new screen and adds a history entry', async () => {
		const push = vi.spyOn( window.history, 'pushState' );
		await swap(
			{
				html: '<h1 id="portier-title" tabindex="-1">Forgot?</h1>',
				focus: 'heading',
			},
			{
				url: `${ window.location.origin }/login/?action=lostpassword`,
				push: true,
			}
		);
		expect( document.activeElement.id ).toBe( 'portier-title' );
		expect( push ).toHaveBeenCalledWith(
			{ portier: true },
			'',
			`${ window.location.origin }/login/?action=lostpassword`
		);
	} );

	it( 'leaves the page for external redirects', async () => {
		const assign = vi.fn();
		vi.stubGlobal( 'location', {
			...window.location,
			assign,
			href: 'http://localhost/login/',
		} );
		await handleResponse(
			{ redirect: 'http://localhost/wp-admin/', internal: false },
			'/login/',
			false
		);
		expect( assign ).toHaveBeenCalledWith( 'http://localhost/wp-admin/' );
		vi.unstubAllGlobals();
	} );
} );

describe( 'form submission', () => {
	it( 'sends the form as a fragment request and swaps the answer in', async () => {
		const fetchMock = vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					html: '<h1 id="portier-title" tabindex="-1">Failed</h1><div id="portier-errors" tabindex="-1"></div>',
					focus: 'errors',
				} ),
		} );
		vi.stubGlobal( 'fetch', fetchMock );
		const form = document.querySelector( 'form' );
		const event = new Event( 'submit', { cancelable: true } );

		await submitForm( form, event );

		expect( event.defaultPrevented ).toBe( true );
		const [ , options ] = fetchMock.mock.calls[ 0 ];
		expect( options.method ).toBe( 'POST' );
		expect( options.headers[ 'X-Portier-Request' ] ).toBe( 'fragment' );
		expect( document.activeElement.id ).toBe( 'portier-errors' );
		vi.unstubAllGlobals();
	} );

	it( 'falls back to a classic submit when the network request fails', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockRejectedValue( new Error( 'offline' ) )
		);
		const submit = vi
			.spyOn( HTMLFormElement.prototype, 'submit' )
			.mockImplementation( () => {} );
		await submitForm(
			document.querySelector( 'form' ),
			new Event( 'submit', { cancelable: true } )
		);
		expect( submit ).toHaveBeenCalled();
		vi.unstubAllGlobals();
	} );

	it( 'ignores double submits while busy', async () => {
		const fetchMock = vi.fn();
		vi.stubGlobal( 'fetch', fetchMock );
		setBusy( document.querySelector( '[data-portier-submit]' ), true );
		await submitForm(
			document.querySelector( 'form' ),
			new Event( 'submit', { cancelable: true } )
		);
		expect( fetchMock ).not.toHaveBeenCalled();
		vi.unstubAllGlobals();
	} );
} );

describe( 'back pill in the header', () => {
	it( 'replaces the logo on follow-up screens and returns', () => {
		document.body.insertAdjacentHTML(
			'beforeend',
			'<a href="/login/" data-portier-back hidden>Back</a><a href="/" data-portier-brand>Site</a>'
		);
		const back = document.querySelector( '[data-portier-back]' );
		const brand = document.querySelector( '[data-portier-brand]' );

		syncBack( 'http://example.org/login/?x=1' );
		expect( back.hidden ).toBe( false );
		expect( brand.hidden ).toBe( true );
		expect( back.getAttribute( 'href' ) ).toBe(
			'http://example.org/login/?x=1'
		);

		syncBack( '' );
		expect( back.hidden ).toBe( true );
		expect( brand.hidden ).toBe( false );
	} );
} );

describe( 'nameParts (view transitions between screens)', () => {
	const login = `
		<div id="portier-card">
			<div class="portier-heading">
				<h1 id="portier-title">Welcome back</h1>
				<p class="portier-lead">Log in to continue.</p>
			</div>
			<form>
				<input type="hidden" name="portier_token" value="x">
				<div class="portier-hp"><input name="website"></div>
				<div class="portier-field" id="identity"><input autocomplete="username"></div>
				<div class="portier-field" id="password"><input type="password"></div>
				<button type="submit" data-portier-submit>Continue</button>
			</form>
			<div class="portier-social" id="social"></div>
			<p class="portier-switch">New here? <a href="#">Create an account</a></p>
		</div>`;

	beforeEach( () => {
		// jsdom has no layout: everything except hidden inputs counts as visible.
		vi.spyOn( Element.prototype, 'getClientRects' ).mockImplementation(
			function () {
				return this.type === 'hidden' ? [] : [ {} ];
			}
		);
		document.body.innerHTML = login;
	} );

	afterEach( () => vi.restoreAllMocks() );

	const nameOf = ( selector ) =>
		document.querySelector( selector ).style.viewTransitionName;

	it( 'pairs the parts both screens have under fixed names', () => {
		nameParts( document.getElementById( 'portier-card' ), 'old' );
		expect( nameOf( '#portier-title' ) ).toBe( 'portier-title' );
		expect( nameOf( '.portier-lead' ) ).toBe( 'portier-lead' );
		expect( nameOf( '#identity' ) ).toBe( 'portier-identity' );
		expect( nameOf( '[data-portier-submit]' ) ).toBe( 'portier-submit' );
		expect( nameOf( '.portier-switch' ) ).toBe( 'portier-switch' );
	} );

	it( 'gives every other visible block a name of its own, containers are split up', () => {
		const named = nameParts(
			document.getElementById( 'portier-card' ),
			'new'
		);
		expect( nameOf( '#password' ) ).toMatch( /^portier-new-\d+$/ );
		expect( nameOf( '#social' ) ).toMatch( /^portier-new-\d+$/ );
		expect( nameOf( '#password' ) ).not.toBe( nameOf( '#social' ) );
		// Containers of shared parts, hidden inputs and the honeypot stay unnamed.
		expect( nameOf( '.portier-heading' ) ).toBe( '' );
		expect( nameOf( 'form' ) ).toBe( '' );
		expect( nameOf( '.portier-hp' ) ).toBe( '' );
		expect( nameOf( 'input[type="hidden"]' ) ).toBe( '' );
		expect(
			new Set( named.map( ( el ) => el.style.viewTransitionName ) ).size
		).toBe( named.length );

		clearParts( named );
		expect( nameOf( '#portier-title' ) ).toBe( '' );
		expect( nameOf( '#password' ) ).toBe( '' );
	} );
} );
