import { useState } from '@wordpress/element';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { __ } from '@wordpress/i18n';
import { StepEditor } from './StepEditor';

/*
 * One step row: a draggable, collapsible summary (position, action label, delay + gate
 * count) that expands into the full StepEditor, plus a delete control. The stable `_key`
 * the reducer assigns is the sortable id, so a row keeps its identity across reorders.
 */
export function StepCard( { step, index, catalog, actionLabel, dispatch } ) {
	const [ open, setOpen ] = useState( false );
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
			<div className="cartquill-builder__step-head">
				<button
					type="button"
					className="cartquill-builder__drag-handle"
					aria-label={ __( 'Reorder step', 'cartquill' ) }
					{ ...attributes }
					{ ...listeners }
				>
					⠿
				</button>
				<span className="cartquill-builder__step-index">
					{ index + 1 }
				</span>
				<button
					type="button"
					className="cartquill-builder__step-toggle"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					<span className="cartquill-builder__step-action">
						{ actionLabel( step.action ) }
					</span>
					{ step.delay > 0 && (
						<span className="cartquill-builder__step-delay">
							{ ` · +${ step.delay }s` }
						</span>
					) }
					{ step.conditions.length > 0 && (
						<span
							className="cartquill-builder__step-gates"
							title={ __( 'Gates', 'cartquill' ) }
						>
							{ ` · ${ step.conditions.length } ⛭` }
						</span>
					) }
				</button>
				<button
					type="button"
					className="button-link-delete"
					onClick={ () => dispatch( { type: 'removeStep', index } ) }
				>
					{ __( 'Delete', 'cartquill' ) }
				</button>
			</div>
			{ open && (
				<StepEditor
					step={ step }
					index={ index }
					catalog={ catalog }
					dispatch={ dispatch }
				/>
			) }
		</li>
	);
}
