/*
 * Pure helpers behind the merge-tag picker. A merge tag is a `{{ key }}` token the
 * engine's Renderer substitutes from the enrollment context; the keys a flow can offer
 * come from its trigger's `context_keys` in the catalog. Kept free of React so the token
 * building, the trigger→keys lookup, and the insert operations unit-test on their own.
 */

/**
 * The `{{ key }}` token for a context key, matching the Renderer's placeholder syntax
 * and the spacing used in the field help examples.
 *
 * @param {string} key the context key
 * @return {string} the merge token
 */
export function mergeToken( key ) {
	return `{{ ${ key } }}`;
}

/**
 * The merge-tag keys available to a flow — the context keys of the trigger matching the
 * flow's type, or none when the type has no known trigger yet.
 *
 * @param {Object} catalog the builder catalog
 * @param {string} type    the flow's trigger type
 * @return {string[]} the available context keys
 */
export function contextKeysFor( catalog, type ) {
	const trigger = ( catalog.triggers || [] ).find(
		( candidate ) => candidate.type === type
	);
	return trigger ? trigger.context_keys || [] : [];
}

/**
 * Append a merge token to a text field, separating it from existing text with a space.
 *
 * @param {string} text the current field text
 * @param {string} key  the context key to insert
 * @return {string} the text with the token appended
 */
export function insertIntoText( text, key ) {
	const token = mergeToken( key );
	if ( ! text ) {
		return token;
	}
	return /\s$/.test( text ) ? text + token : `${ text } ${ token }`;
}

/**
 * Append a merge token as a new item in a list field (e.g. a Sheets row column).
 *
 * @param {*}      list the current list value (an array, or anything else)
 * @param {string} key  the context key to insert
 * @return {string[]} the list with the token appended
 */
export function insertIntoList( list, key ) {
	return [ ...( Array.isArray( list ) ? list : [] ), mergeToken( key ) ];
}
