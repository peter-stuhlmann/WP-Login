/**
 * Custom CSS editor (Design tab): WordPress' own code editor (CodeMirror with CSSLint) in a
 * light theme, with formatting and a warning for invalid CSS.
 *
 * - Syntax highlighting, line numbers, bracket matching.
 * - CSSLint checks while typing; errors are marked in the gutter and listed in a warning above
 *   the editor (announced to screen readers). Saving stays possible – the server cleans anyway.
 * - Formatting with the button, Shift+Alt+F, and automatically when leaving the editor – but
 *   only valid CSS, so half-written code is never rearranged.
 * - People who switched off syntax highlighting in their profile get the plain text field (as
 *   everywhere in WordPress); formatting works there too.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { cssErrors, formatCss } from '../css-format';

/**
 * @param {Object}   props
 * @param {string}   props.id          Id of the text field (the label points to it).
 * @param {string}   props.labelId     Id of the visible label.
 * @param {string}   props.value
 * @param {Function} props.onChange    Receives the new CSS.
 * @param {boolean}  props.readOnly
 * @param {string}   props.describedBy Ids of help texts.
 * @param {boolean}  props.invalid     Server-side error for the field.
 */
export default function CssEditor( {
	id,
	labelId,
	value,
	onChange,
	readOnly,
	describedBy,
	invalid,
} ) {
	const textarea = useRef( null );
	const editor = useRef( null );
	const latest = useRef( { onChange, errors: [] } );
	const [ errors, setErrors ] = useState( [] );
	latest.current.onChange = onChange;
	latest.current.errors = errors;

	const format = () => {
		const cm = editor.current;
		const current = cm ? cm.getValue() : value;
		const formatted = formatCss( current );
		if ( formatted === current ) {
			return;
		}
		if ( cm ) {
			const cursor = cm.getCursor();
			cm.setValue( formatted );
			cm.setCursor( cursor );
		}
		latest.current.onChange( formatted );
	};

	// WordPress' code editor on top of the text field (once).
	useEffect( () => {
		const settings = window.portierCodeEditor;
		const codeEditor = window.wp?.codeEditor;
		if ( ! settings || ! codeEditor || ! textarea.current ) {
			return undefined;
		}
		const instance = codeEditor.initialize( textarea.current, {
			...settings,
			codemirror: {
				...settings.codemirror,
				theme: 'portier',
				lineNumbers: true,
				lineWrapping: true,
				indentWithTabs: true,
				readOnly,
				extraKeys: {
					...( settings.codemirror?.extraKeys || {} ),
					'Shift-Alt-F': () => format(),
				},
			},
			onChangeLintingErrors: ( errorAnnotations ) =>
				setErrors( cssErrors( errorAnnotations ) ),
		} );
		const cm = instance.codemirror;
		editor.current = cm;
		const input = cm.getInputField();
		input.setAttribute( 'aria-labelledby', labelId );
		input.setAttribute( 'aria-describedby', describedBy );
		cm.on( 'change', ( _cm, change ) => {
			if ( change.origin !== 'setValue' ) {
				latest.current.onChange( cm.getValue() );
			}
		} );
		// Leaving the editor tidies valid CSS.
		cm.on( 'blur', () => {
			if (
				! cm.getOption( 'readOnly' ) &&
				latest.current.errors.length === 0
			) {
				format();
			}
		} );
		return () => {
			cm.toTextArea();
			editor.current = null;
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Set up once; live values come from refs.

	// Outside changes (e.g. an older version restored) reach the editor.
	useEffect( () => {
		const cm = editor.current;
		if ( cm && cm.getValue() !== value ) {
			cm.setValue( value );
		}
	}, [ value ] );

	useEffect( () => {
		editor.current?.setOption( 'readOnly', readOnly );
	}, [ readOnly ] );

	return (
		<div className="portier-css-editor">
			{ errors.length > 0 && (
				<div
					className="portier-notice portier-notice--warning portier-css-editor__warning"
					role="status"
				>
					<p>
						{ sprintf(
							/* translators: %d: number of errors */
							_n(
								'The CSS is not valid (%d error). Invalid rules are removed when saving.',
								'The CSS is not valid (%d errors). Invalid rules are removed when saving.',
								errors.length,
								'portier'
							),
							errors.length
						) }
					</p>
					<ul>
						{ errors.slice( 0, 5 ).map( ( error, index ) => (
							<li key={ index }>
								{ sprintf(
									/* translators: 1: line number, 2: message from the CSS checker */
									__( 'Line %1$d: %2$s', 'portier' ),
									error.line,
									error.message
								) }
							</li>
						) ) }
					</ul>
				</div>
			) }
			<textarea
				ref={ textarea }
				id={ id }
				className="portier-code-editor"
				rows={ 10 }
				value={ value }
				readOnly={ readOnly }
				spellCheck={ false }
				aria-describedby={ describedBy }
				aria-invalid={ invalid || errors.length > 0 ? true : undefined }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
			{ ! readOnly && (
				<div className="portier-css-editor__tools">
					<button
						type="button"
						className="portier-btn portier-btn--secondary portier-btn--sm"
						onClick={ format }
						disabled={ errors.length > 0 || ! value.trim() }
					>
						{ __( 'Format', 'portier' ) }
					</button>
					<span className="portier-field__help">
						{ __(
							'Shift+Alt+F formats, too. In the editor, Esc and then Tab leaves it.',
							'portier'
						) }
					</span>
				</div>
			) }
		</div>
	);
}
