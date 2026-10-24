/**
 * Confirmation dialog for consequential choices (e.g. switching the audit log off).
 *
 * Built on the native <dialog> element opened with showModal(): the rest of the page becomes
 * inert, focus stays inside, Esc cancels, and focus returns to where it was. It starts on the
 * safe choice ("Cancel"); the dialog is an alertdialog with title and text as name and
 * description.
 */
import { useEffect, useId, useRef } from '@wordpress/element';
import { AlertIcon } from './icons';

/**
 * @param {Object}   props
 * @param {boolean}  props.open
 * @param {string}   props.title
 * @param {*}        props.children     Explanation.
 * @param {string}   props.confirmLabel Label of the consequential action.
 * @param {string}   props.cancelLabel
 * @param {Function} props.onConfirm
 * @param {Function} props.onCancel     Also called for Esc and a click on the backdrop.
 */
export default function ConfirmDialog( {
	open,
	title,
	children,
	confirmLabel,
	cancelLabel,
	onConfirm,
	onCancel,
} ) {
	const dialog = useRef( null );
	const cancel = useRef( null );
	const returnTo = useRef( null );
	const id = useId();

	useEffect( () => {
		const element = dialog.current;
		if ( ! element ) {
			return;
		}
		if ( open && ! element.open ) {
			returnTo.current = element.ownerDocument.activeElement;
			element.showModal();
			cancel.current?.focus();
		} else if ( ! open && element.open ) {
			element.close();
		}
	}, [ open ] );

	// After closing, focus goes back to the control that opened the dialog.
	const onClose = () => {
		returnTo.current?.focus?.();
	};

	return (
		// The click handler only catches clicks on the backdrop; Esc is its keyboard equivalent.
		// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-noninteractive-element-interactions
		<dialog
			ref={ dialog }
			className="portier-dialog"
			role="alertdialog"
			aria-modal="true"
			aria-labelledby={ `${ id }-title` }
			aria-describedby={ `${ id }-text` }
			onCancel={ ( event ) => {
				event.preventDefault(); // Closed through state, like the buttons.
				onCancel();
			} }
			onClose={ onClose }
			onClick={ ( event ) => {
				// A click on the backdrop (outside the content) cancels.
				if ( event.target === dialog.current ) {
					onCancel();
				}
			} }
		>
			<div className="portier-dialog__content">
				<span className="portier-dialog__icon" aria-hidden="true">
					<AlertIcon size={ 22 } />
				</span>
				<h2 className="portier-dialog__title" id={ `${ id }-title` }>
					{ title }
				</h2>
				<div className="portier-dialog__text" id={ `${ id }-text` }>
					{ children }
				</div>
				<div className="portier-dialog__actions">
					<button
						ref={ cancel }
						type="button"
						className="portier-btn portier-btn--secondary"
						onClick={ onCancel }
					>
						{ cancelLabel }
					</button>
					<button
						type="button"
						className="portier-btn portier-btn--danger"
						onClick={ onConfirm }
					>
						{ confirmLabel }
					</button>
				</div>
			</div>
		</dialog>
	);
}
