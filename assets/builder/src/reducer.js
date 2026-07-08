/*
 * The flow builder's editable state and the pure operations over it. Kept free of
 * React and @dnd-kit so it can be unit-tested on its own: the components dispatch
 * these actions, and `buildPayload` produces the exact wire shape the REST write
 * API (`FlowValidator`) reads back.
 */

let keySeq = 0;

/**
 * A process-unique key for a step, used as its stable @dnd-kit sortable id. Reorders
 * carry the key with the step so React and the drag layer keep tracking the same row.
 *
 * @return {string} a unique step key
 */
export function nextKey() {
	keySeq += 1;
	return `step-${ keySeq }`;
}

/**
 * Tag each step with a stable `_key`. `buildPayload` strips it before saving.
 *
 * @param {Array} steps the serialized steps, or undefined
 * @return {Array} the steps with a `_key` each
 */
export function withKeys( steps ) {
	return ( steps || [] ).map( ( step ) => ( { ...step, _key: nextKey() } ) );
}

/**
 * Move an item between positions without mutating the input list.
 *
 * @param {Array}  list the list to reorder
 * @param {number} from the source index
 * @param {number} to   the destination index
 * @return {Array} a new reordered list
 */
export function arrayMove( list, from, to ) {
	const next = list.slice();
	const [ moved ] = next.splice( from, 1 );
	next.splice( to, 0, moved );
	return next;
}

/**
 * Seed editable state from a loaded flow (or null for a new one). Never dirty — the
 * dirty flag only trips on a user edit.
 *
 * @param {Object|null} flow the serialized flow from the REST API
 * @return {Object} the editable builder state
 */
export function initialFlowState( flow ) {
	return {
		id: flow?.id ?? 0,
		name: flow?.name ?? '',
		type: flow?.type ?? '',
		status: flow?.status ?? 'draft',
		source: flow?.source ?? 'builder',
		steps: withKeys( flow?.steps ),
		dirty: false,
	};
}

/**
 * The pure state transition for the builder.
 *
 * @param {Object} state  the current editable state
 * @param {Object} action the dispatched action
 * @return {Object} the next state
 */
export function flowReducer( state, action ) {
	switch ( action.type ) {
		case 'load':
			return initialFlowState( action.flow );
		case 'setName':
			return { ...state, name: action.name, dirty: true };
		case 'setStatus':
			return { ...state, status: action.status, dirty: true };
		case 'moveStep':
			return {
				...state,
				steps: arrayMove( state.steps, action.from, action.to ),
				dirty: true,
			};
		case 'saved':
			return initialFlowState( action.flow );
		default:
			return state;
	}
}

/**
 * The wire payload for a create/save request — the exact shape the REST write API
 * validates and stores, with the client-only `_key` removed from each step.
 *
 * @param {Object} state the editable builder state
 * @return {Object} the flow payload
 */
export function buildPayload( state ) {
	return {
		name: state.name,
		type: state.type,
		status: state.status,
		steps: ( state.steps || [] ).map( ( { _key, ...step } ) => step ),
	};
}
