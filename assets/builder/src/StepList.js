import {
	DndContext,
	KeyboardSensor,
	PointerSensor,
	closestCenter,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	SortableContext,
	sortableKeyboardCoordinates,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { __ } from '@wordpress/i18n';
import { StepCard } from './StepCard';

/*
 * The vertical, drag-to-reorder list of step cards. It owns the @dnd-kit context and
 * sensors (pointer with a small drag threshold so clicks still work, plus keyboard for
 * accessibility) and dispatches `moveStep` on a completed drag. It resolves each step's
 * human action label from the catalog and hands the catalog + dispatch down so a card
 * can expand into its editor. It stays otherwise presentational — state lives in the
 * reducer.
 */
export function StepList( {
	steps,
	catalog,
	mergeTags,
	api,
	aiRewrite,
	dispatch,
} ) {
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 5 } } ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);

	const actionLabel = ( type ) => {
		const found = ( catalog.actions || [] ).find(
			( action ) => action.type === type
		);
		return found ? found.label : type;
	};

	const onDragEnd = ( event ) => {
		const { active, over } = event;
		if ( ! over || active.id === over.id ) {
			return;
		}
		const from = steps.findIndex( ( step ) => step._key === active.id );
		const to = steps.findIndex( ( step ) => step._key === over.id );
		if ( from !== -1 && to !== -1 ) {
			dispatch( { type: 'moveStep', from, to } );
		}
	};

	if ( ! steps.length ) {
		return (
			<p className="cartquill-builder__empty">
				{ __( 'This flow has no steps yet.', 'cartquill' ) }
			</p>
		);
	}

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ closestCenter }
			onDragEnd={ onDragEnd }
		>
			<SortableContext
				items={ steps.map( ( step ) => step._key ) }
				strategy={ verticalListSortingStrategy }
			>
				<ol className="cartquill-builder__steps">
					{ steps.map( ( step, index ) => (
						<StepCard
							key={ step._key }
							step={ step }
							index={ index }
							catalog={ catalog }
							mergeTags={ mergeTags }
							api={ api }
							aiRewrite={ aiRewrite }
							actionLabel={ actionLabel }
							dispatch={ dispatch }
						/>
					) ) }
				</ol>
			</SortableContext>
		</DndContext>
	);
}
