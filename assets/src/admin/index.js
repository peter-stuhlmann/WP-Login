/**
 * Portier admin app entry point.
 *
 * The page arrives with the layout of the tab and placeholders (src/Admin/Skeleton.php) and
 * with the settings preloaded. The app is mounted once the settings are there, so the
 * placeholders are replaced by the finished screen in one step – no loading state in between.
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import App, { SETTINGS_PATH } from './App';
import './admin.css';

const root = document.getElementById( 'portier-admin-root' );
if ( root ) {
	const mount = ( props ) =>
		createRoot( root ).render(
			<App
				initialTab={ root.dataset.tab || 'methods' }
				version={ root.dataset.version || '' }
				{ ...props }
			/>
		);
	apiFetch( { path: SETTINGS_PATH } )
		.then( ( data ) => mount( { initialData: data } ) )
		.catch( ( error ) =>
			mount( {
				loadError:
					error?.message ||
					__( 'The settings could not be loaded.', 'portier' ),
			} )
		);
}
