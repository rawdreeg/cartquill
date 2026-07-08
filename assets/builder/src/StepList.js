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
 * sensors (pointer with a small drag threshold so clicks still work, plus keyboard
 * for accessibility) and translates a completed drag into a `moveStep` on the parent
 * via `onMove(from, to)`. It stays presentational — the flow state lives in the reducer.
 */
export function StepList( { steps, actionLabel, onMove } ) {
	const sensors = useSensors(
		useSensor( PointerSensor, { activationConstraint: { distance: 5 } } ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);

	const onDragEnd = ( event ) => {
		const { active, over } = event;
		if ( ! over || active.id === over.id ) {
			return;
		}
		const from = steps.findIndex( ( step ) => step._key === active.id );
		const to = steps.findIndex( ( step ) => step._key === over.id );
		if ( from !== -1 && to !== -1 ) {
			onMove( from, to );
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
							actionLabel={ actionLabel }
						/>
					) ) }
				</ol>
			</SortableContext>
		</DndContext>
	);
}
