/**
 * Accessible tabs (WAI-ARIA Authoring Practices, automatic activation).
 *
 * Arrow keys move between tabs, Home/End jump to the first/last tab. Only the active tab is
 * in the Tab order, so keyboard users reach the panel content with a single Tab press.
 */
import { useRef } from '@wordpress/element';

/**
 * @param {Object}                               props
 * @param {Array<{name: string, title: string}>} props.tabs
 * @param {string}                               props.active
 * @param {Function}                             props.onSelect
 * @param {string}                               props.label    Accessible name of the tab list.
 */
export function TabList( { tabs, active, onSelect, label } ) {
	const refs = useRef( {} );

	const onKeyDown = ( event ) => {
		const index = tabs.findIndex( ( tab ) => tab.name === active );
		const targets = {
			ArrowRight: ( index + 1 ) % tabs.length,
			ArrowLeft: ( index - 1 + tabs.length ) % tabs.length,
			Home: 0,
			End: tabs.length - 1,
		};
		if ( ! ( event.key in targets ) ) {
			return;
		}
		event.preventDefault();
		const next = tabs[ targets[ event.key ] ];
		onSelect( next.name );
		refs.current[ next.name ]?.focus();
	};

	return (
		<div className="portier-tabs" role="tablist" aria-label={ label }>
			{ tabs.map( ( tab ) => {
				const selected = tab.name === active;
				return (
					<button
						key={ tab.name }
						ref={ ( node ) => ( refs.current[ tab.name ] = node ) }
						type="button"
						role="tab"
						id={ `portier-tab-${ tab.name }` }
						aria-controls={ `portier-panel-${ tab.name }` }
						aria-selected={ selected }
						tabIndex={ selected ? 0 : -1 }
						className={ `portier-tab${ selected ? ' is-active' : '' }` }
						onClick={ () => onSelect( tab.name ) }
						onKeyDown={ onKeyDown }
					>
						{ tab.title }
					</button>
				);
			} ) }
		</div>
	);
}

/**
 * @param {Object} props
 * @param {string} props.name
 * @param {*}      props.children
 */
export function TabPanel( { name, children } ) {
	return (
		<div
			className="portier-panel"
			role="tabpanel"
			id={ `portier-panel-${ name }` }
			aria-labelledby={ `portier-tab-${ name }` }
		>
			{ children }
		</div>
	);
}
