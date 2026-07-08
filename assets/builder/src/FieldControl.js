/*
 * One descriptor-driven form control. It renders the right input for an action field
 * or a condition param straight from its catalog descriptor — text, textarea/html,
 * number, a fixed-option select, or a newline-per-item list (the Sheets row columns) —
 * and reports edits through onChange. Because the shape is uniform, a new channel needs
 * no bespoke form. Merge-tag insertion (5c) layers on top of the text-like controls.
 */
export function FieldControl( { field, value, onChange, idPrefix = '' } ) {
	const id = `${ idPrefix }-${ field.key }`;

	let control;
	if ( 'select' === field.type ) {
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
	} else if ( 'list' === field.type ) {
		const lines = Array.isArray( value ) ? value : [];
		control = (
			<textarea
				id={ id }
				value={ lines.join( '\n' ) }
				onChange={ ( event ) =>
					onChange( event.target.value.split( '\n' ) )
				}
			/>
		);
	} else if ( 'textarea' === field.type || 'html' === field.type ) {
		control = (
			<textarea
				id={ id }
				value={ value ?? '' }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		);
	} else {
		control = (
			<input
				id={ id }
				type={ 'number' === field.type ? 'number' : 'text' }
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
