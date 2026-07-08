import { __ } from '@wordpress/i18n';
import { mergeToken } from './mergeTags';

/*
 * A compact picker for inserting a merge tag into a field. It lists the merge-tag keys a
 * flow's trigger exposes (as their `{{ key }}` tokens) and calls onInsert with the chosen
 * key; the select is value-pinned to the placeholder so it resets after each pick. Renders
 * nothing when the flow's trigger offers no context keys.
 */
export function MergeTagPicker( { tags, onInsert } ) {
	if ( ! tags || ! tags.length ) {
		return null;
	}

	return (
		<select
			className="cartquill-builder__merge-picker"
			aria-label={ __( 'Insert a merge tag', 'cartquill' ) }
			value=""
			onChange={ ( event ) => {
				if ( event.target.value ) {
					onInsert( event.target.value );
				}
			} }
		>
			<option value="">{ __( 'Insert merge tag…', 'cartquill' ) }</option>
			{ tags.map( ( key ) => (
				<option key={ key } value={ key }>
					{ mergeToken( key ) }
				</option>
			) ) }
		</select>
	);
}
