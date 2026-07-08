import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { __ } from '@wordpress/i18n';

/*
 * One draggable step row. It renders a read-only summary (position, action label,
 * delay) plus a keyboard-accessible drag handle wired to @dnd-kit's sortable hook;
 * inline field editing arrives in the next slice. The stable `_key` the reducer
 * assigns is the sortable id, so a row keeps its identity across reorders.
 */
export function StepCard( { step, index, actionLabel } ) {
	const {
		attributes,
		listeners,
		setNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( { id: step._key } );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.6 : 1,
	};

	return (
		<li
			ref={ setNodeRef }
			style={ style }
			className="cartquill-builder__step"
		>
			<button
				type="button"
				className="cartquill-builder__drag-handle"
				aria-label={ __( 'Reorder step', 'cartquill' ) }
				{ ...attributes }
				{ ...listeners }
			>
				⠿
			</button>
			<span className="cartquill-builder__step-index">{ index + 1 }</span>
			<span className="cartquill-builder__step-action">
				{ actionLabel( step.action ) }
			</span>
			{ step.delay > 0 && (
				<span className="cartquill-builder__step-delay">
					{ ` · +${ step.delay }s` }
				</span>
			) }
		</li>
	);
}
