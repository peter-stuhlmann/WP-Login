/**
 * Drag handle of a sortable list (Figma "Ziehgriff (Reihenfolge, auch per Tastatur)").
 *
 * A real button: arrow up/down move the entry one place, Home/End to the start or end; with a
 * pointer the entry follows while dragging. The list announces the new position (see
 * MethodsTab). Entries are marked with `data-sort-id` so the pointer can find its place.
 */
import { useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { GripIcon } from './icons';

/**
 * @param {Object}                           props
 * @param {string}                           props.id     Entry id (also the button id suffix).
 * @param {string}                           props.name   Shown name, e.g. "Google".
 * @param {number}                           props.index  Current position (0-based).
 * @param {number}                           props.count  Number of entries.
 * @param {(from: number, to: number)=>void} props.onMove Moves the entry.
 * @param {string}                           props.hintId Id of the shared usage hint.
 */
export default function SortHandle( {
	id,
	name,
	index,
	count,
	onMove,
	hintId,
} ) {
	const dragging = useRef( null );

	const onKeyDown = ( event ) => {
		const targets = {
			ArrowUp: index - 1,
			ArrowDown: index + 1,
			Home: 0,
			End: count - 1,
		};
		if ( ! ( event.key in targets ) ) {
			return;
		}
		event.preventDefault();
		onMove( index, targets[ event.key ] );
	};

	// Pointer: the entry moves as soon as the pointer passes the middle of a neighbour.
	const onPointerDown = ( event ) => {
		if ( event.button !== 0 ) {
			return;
		}
		event.currentTarget.setPointerCapture?.( event.pointerId );
		dragging.current = { pointer: event.pointerId };
		event.currentTarget
			.closest( '[data-sort-id]' )
			?.classList.add( 'is-dragging' );
	};

	const onPointerMove = ( event ) => {
		if ( dragging.current?.pointer !== event.pointerId ) {
			return;
		}
		const entries = [
			...event.currentTarget
				.closest( '[data-sort-list]' )
				.querySelectorAll( '[data-sort-id]' ),
		];
		const from = entries.findIndex(
			( entry ) => entry.dataset.sortId === id
		);
		const to = entries.findIndex( ( entry ) => {
			const box = entry.getBoundingClientRect();
			return event.clientY < box.top + box.height / 2;
		} );
		const target = to === -1 ? entries.length - 1 : to;
		// Moving down: the entry takes the place before the found neighbour.
		const place = target > from ? target - 1 : target;
		if ( place !== from ) {
			onMove( from, place );
		}
	};

	const onPointerUp = ( event ) => {
		if ( dragging.current?.pointer !== event.pointerId ) {
			return;
		}
		dragging.current = null;
		event.currentTarget
			.closest( '[data-sort-id]' )
			?.classList.remove( 'is-dragging' );
	};

	return (
		<button
			type="button"
			id={ `portier-sort-${ id }` }
			className="portier-sort-handle"
			aria-label={ sprintf(
				/* translators: 1: method name, 2: position, 3: number of methods */
				__( 'Move %1$s (position %2$d of %3$d)', 'portier' ),
				name,
				index + 1,
				count
			) }
			aria-describedby={ hintId }
			onKeyDown={ onKeyDown }
			onPointerDown={ onPointerDown }
			onPointerMove={ onPointerMove }
			onPointerUp={ onPointerUp }
			onPointerCancel={ onPointerUp }
		>
			<GripIcon size={ 18 } />
		</button>
	);
}
