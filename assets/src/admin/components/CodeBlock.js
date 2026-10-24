/**
 * Dark code block with a copy button (Figma "Notfall-Zugang").
 */
import { __ } from '@wordpress/i18n';
import { CopyIcon } from './icons';

/**
 * @param {Object}   props
 * @param {string}   props.code
 * @param {Function} props.onCopied Called after a successful copy (for the status message).
 */
export function CodeBlock( { code, onCopied } ) {
	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( code );
			onCopied( __( 'Copied to the clipboard.', 'portier' ) );
		} catch {
			onCopied(
				__(
					'Copying failed. Please select the text and copy it manually.',
					'portier'
				)
			);
		}
	};
	return (
		<div className="portier-code">
			<code className="portier-code__text">
				<span className="portier-code__fn">{ 'define' }</span>
				{ '( ' }
				<span className="portier-code__str">
					{ "'PORTIER_DISABLE'" }
				</span>
				{ ', ' }
				<span className="portier-code__bool">{ 'true' }</span>
				{ ' );' }
			</code>
			<button
				type="button"
				className="portier-code__copy"
				onClick={ copy }
			>
				<CopyIcon size={ 16 } />
				<span className="screen-reader-text">
					{ __( 'Copy code', 'portier' ) }
				</span>
			</button>
		</div>
	);
}
