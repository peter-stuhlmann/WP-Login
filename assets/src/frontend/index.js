/**
 * Entry point of the login screen script.
 */
import './login.css';
import './templates.css';
import './photo.css';
import {
	bindGlobalEvents,
	enhance,
	forgetLanguageParam,
	listenForPreviewCss,
} from './enhance';

document.documentElement.classList.replace( 'no-js', 'js' );
bindGlobalEvents();
forgetLanguageParam();
if ( document.documentElement.hasAttribute( 'data-portier-preview' ) ) {
	listenForPreviewCss();
}
enhance( document );

// After a server-side validation error (no JS round trip), bring the error summary into focus.
document.getElementById( 'portier-errors' )?.focus();
