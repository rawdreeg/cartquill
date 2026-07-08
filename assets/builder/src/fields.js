/*
 * Pure helpers behind the descriptor-driven field control. Kept free of React (and of
 * the externalized @wordpress/element) so the descriptor→control mapping and the list /
 * number conversions can be unit-tested on their own — the same seam pattern the reducer
 * and api client use.
 */

/**
 * Which control a field descriptor renders as. Everything the catalog does not name
 * explicitly falls back to a plain text input.
 *
 * @param {Object} field the field/param descriptor
 * @return {string} one of 'select' | 'list' | 'number' | 'textarea' | 'text'
 */
export function controlType( field ) {
	switch ( field.type ) {
		case 'select':
		case 'list':
		case 'number':
			return field.type;
		case 'textarea':
		case 'html':
			return 'textarea';
		default:
			return 'text';
	}
}

/**
 * Split textarea text into a clean list — one non-empty line per item, so an emptied
 * field stores `[]` (not `['']`) and a trailing newline adds no blank entry.
 *
 * @param {string} text the textarea contents
 * @return {string[]} the list items
 */
export function linesToArray( text ) {
	return text.split( '\n' ).filter( ( line ) => '' !== line );
}

/**
 * Join a stored list back into newline-separated text for the textarea.
 *
 * @param {*} value the stored value (an array, or anything else)
 * @return {string} the textarea contents
 */
export function arrayToLines( value ) {
	return ( Array.isArray( value ) ? value : [] ).join( '\n' );
}

/**
 * Coerce editable text to a non-negative integer (the stored delay contract).
 *
 * @param {string} text the raw field text
 * @return {number} a non-negative integer
 */
export function clampInt( text ) {
	return Math.max( 0, parseInt( text, 10 ) || 0 );
}

/**
 * The display text for a stored number, keeping an explicitly empty value blank.
 *
 * @param {*} value the stored value
 * @return {string} the display text
 */
export function numberToText( value ) {
	return null === value || undefined === value || '' === value
		? ''
		: String( value );
}
