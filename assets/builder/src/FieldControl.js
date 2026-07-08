import { useEffect, useState } from '@wordpress/element';
import {
	arrayToLines,
	clampInt,
	controlType,
	linesToArray,
	numberToText,
} from './fields';

/*
 * One descriptor-driven form control. It renders the right input for an action field
 * or a condition param straight from its catalog descriptor — text, textarea/html,
 * number, a fixed-option select, or a newline-per-item list (the Sheets row columns) —
 * and reports edits through onChange. Because the shape is uniform, a new channel needs
 * no bespoke form. Merge-tag insertion (5c) layers on top of the text-like controls.
 *
 * The list and number controls keep a local text buffer so the displayed text can
 * differ from the stored value: the list stores a clean array (no empty entries) while
 * the textarea keeps the newline the user is mid-typing, and the number stores a
 * non-negative integer while the field can still be transiently cleared. The mapping
 * and conversions live in the pure ./fields module.
 */

function ListField( { id, value, onChange } ) {
	const [ text, setText ] = useState( () => arrayToLines( value ) );

	useEffect( () => {
		const external = arrayToLines( value );
		if ( external !== arrayToLines( linesToArray( text ) ) ) {
			setText( external );
		}
		// Sync only on external value changes, not on our own echo.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value ] );

	return (
		<textarea
			id={ id }
			value={ text }
			onChange={ ( event ) => {
				setText( event.target.value );
				onChange( linesToArray( event.target.value ) );
			} }
		/>
	);
}

function NumberField( { id, value, onChange } ) {
	const [ text, setText ] = useState( () => numberToText( value ) );

	useEffect( () => {
		if ( clampInt( text ) !== ( Number( value ) || 0 ) ) {
			setText( numberToText( value ) );
		}
		// Sync only on external value changes, not on our own echo.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value ] );

	return (
		<input
			id={ id }
			type="number"
			min="0"
			value={ text }
			onChange={ ( event ) => {
				setText( event.target.value );
				onChange( event.target.value );
			} }
		/>
	);
}

export function FieldControl( { field, value, onChange, idPrefix = '' } ) {
	const id = `${ idPrefix }-${ field.key }`;

	let control;
	switch ( controlType( field ) ) {
		case 'select':
			control = (
				<select
					id={ id }
					value={ value ?? '' }
					onChange={ ( event ) => onChange( event.target.value ) }
				>
					{ ( field.options || [] ).map( ( option ) => (
						<option key={ option } value={ option }>
							{ option }
						</option>
					) ) }
				</select>
			);
			break;
		case 'list':
			control = (
				<ListField id={ id } value={ value } onChange={ onChange } />
			);
			break;
		case 'number':
			control = (
				<NumberField id={ id } value={ value } onChange={ onChange } />
			);
			break;
		case 'textarea':
			control = (
				<textarea
					id={ id }
					value={ value ?? '' }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>
			);
			break;
		default:
			control = (
				<input
					id={ id }
					type="text"
					value={ value ?? '' }
					onChange={ ( event ) => onChange( event.target.value ) }
				/>
			);
	}

	return (
		<div className="cartquill-builder__field">
			<label htmlFor={ id }>
				{ field.label }
				{ field.required && (
					<span className="cartquill-builder__required"> *</span>
				) }
			</label>
			{ control }
			{ field.help && (
				<p className="cartquill-builder__help">{ field.help }</p>
			) }
		</div>
	);
}
