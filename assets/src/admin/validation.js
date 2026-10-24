import { __ } from '@wordpress/i18n';

/**
 * Client-side mirror of the server rules in Settings::validateSlug() for instant feedback.
 * The server stays the authority – it validates again on save.
 */
export const RESERVED_SLUGS = [
	'wp-admin',
	'wp-login',
	'wp-login.php',
	'wp-content',
	'wp-includes',
	'wp-json',
	'admin',
	'dashboard',
	'feed',
	'rss',
	'rss2',
	'atom',
	'rdf',
	'comments',
	'page',
	'embed',
	'trackback',
	'xmlrpc',
	'xmlrpc.php',
	'sitemap',
	'wp-sitemap',
	'robots.txt',
	'favicon.ico',
	'index.php',
	'search',
	'author',
	'category',
	'tag',
];

/**
 * @param {string} value
 * @return {string} Normalised slug (lowercase, hyphens).
 */
export function normaliseSlug( value ) {
	return value
		.trim()
		.toLowerCase()
		.replace( /^\/+|\/+$/g, '' )
		.replace( /[^a-z0-9-]+/g, '-' );
}

/**
 * @param {string} slug
 * @return {'slug_format'|'slug_reserved'|null} Error code or null if valid.
 */
export function validateSlug( slug ) {
	if (
		slug.length < 2 ||
		slug.length > 50 ||
		! /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test( slug )
	) {
		return 'slug_format';
	}
	if ( RESERVED_SLUGS.includes( slug ) ) {
		return 'slug_reserved';
	}
	return null;
}

/**
 * Login URL preview for a slug, based on the URL of the currently saved slug.
 *
 * @param {string} currentUrl  URL returned by the server for the saved slug.
 * @param {string} currentSlug Saved slug.
 * @param {string} nextSlug    Slug being edited.
 * @return {string} Preview URL.
 */
export function previewUrl( currentUrl, currentSlug, nextSlug ) {
	if ( ! currentUrl.includes( `/${ currentSlug }` ) ) {
		return currentUrl; // Plain permalinks: the URL does not contain the slug.
	}
	const index = currentUrl.lastIndexOf( `/${ currentSlug }` );
	return (
		currentUrl.slice( 0, index ) +
		`/${ nextSlug }` +
		currentUrl.slice( index + currentSlug.length + 1 )
	);
}

/**
 * Visible prefix in front of the slug input, e.g. "example.org/" (scheme removed).
 *
 * @param {string} loginUrl Preview URL containing the slug.
 * @param {string} slug     Slug being edited.
 * @return {string} Prefix, or an empty string for plain permalinks.
 */
export function urlPrefix( loginUrl, slug ) {
	const withoutScheme = loginUrl.replace( /^https?:\/\//, '' );
	const index = slug ? withoutScheme.lastIndexOf( `/${ slug }` ) : -1;
	return index === -1 ? '' : withoutScheme.slice( 0, index + 1 );
}

/**
 * Translated message for a slug error code.
 *
 * @param {'slug_format'|'slug_reserved'} code
 * @return {string} Message.
 */
export function slugMessage( code ) {
	return code === 'slug_reserved'
		? __(
				'This address is reserved by WordPress. Please choose another one.',
				'portier'
			)
		: __(
				'Use 2–50 lowercase letters, digits or hyphens (no hyphen at the start or end).',
				'portier'
			);
}
