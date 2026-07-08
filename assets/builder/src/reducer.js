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
 * The default config for a freshly added step: every field seeded to its descriptor
 * default. A `list` field (e.g. the Sheets row columns) is an array, so it round-trips
 * as the multi-value shape the engine stores; the rest take their scalar default.
 *
 * @param {Array} fields the action's field descriptors
 * @return {Object} the seeded config map
 */
export function defaultConfig( fields ) {
	const config = {};
	( fields || [] ).forEach( ( field ) => {
		config[ field.key ] = 'list' === field.type ? [] : field.default ?? '';
	} );
	return config;
}

/**
 * A new condition seeded from its catalog descriptor: the `type` discriminator plus
 * each param at its default. Matches the stored gate shape `{ type, ...params }`.
 *
 * @param {Object} descriptor the catalog condition descriptor
 * @return {Object} the seeded condition
 */
export function defaultCondition( descriptor ) {
	const condition = { type: descriptor.type };
	( descriptor.params || [] ).forEach( ( param ) => {
		condition[ param.key ] = param.default ?? '';
	} );
	return condition;
}

/**
 * Replace the step at `index` with the result of `fn`, leaving the others untouched.
 *
 * @param {Array}    steps the step list
 * @param {number}   index the step to replace
 * @param {Function} fn    maps the old step to the new one
 * @return {Array} a new step list
 */
function updateStep( steps, index, fn ) {
	return steps.map( ( step, i ) => ( i === index ? fn( step ) : step ) );
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
		case 'addStep':
			return {
				...state,
				steps: [
					...state.steps,
					{
						_key: nextKey(),
						delay: 0,
						action: action.descriptor.type,
						config: defaultConfig( action.descriptor.fields ),
						conditions: [],
					},
				],
				dirty: true,
			};
		case 'removeStep':
			return {
				...state,
				steps: state.steps.filter( ( step, i ) => i !== action.index ),
				dirty: true,
			};
		case 'setStepDelay':
			return {
				...state,
				steps: updateStep( state.steps, action.index, ( step ) => ( {
					...step,
					delay: Math.max( 0, parseInt( action.delay, 10 ) || 0 ),
				} ) ),
				dirty: true,
			};
		case 'setStepConfig':
			return {
				...state,
				steps: updateStep( state.steps, action.index, ( step ) => ( {
					...step,
					config: { ...step.config, [ action.key ]: action.value },
				} ) ),
				dirty: true,
			};
		case 'addCondition':
			return {
				...state,
				steps: updateStep( state.steps, action.index, ( step ) => ( {
					...step,
					conditions: [
						...step.conditions,
						defaultCondition( action.descriptor ),
					],
				} ) ),
				dirty: true,
			};
		case 'removeCondition':
			return {
				...state,
				steps: updateStep( state.steps, action.index, ( step ) => ( {
					...step,
					conditions: step.conditions.filter(
						( condition, i ) => i !== action.ci
					),
				} ) ),
				dirty: true,
			};
		case 'setConditionParam':
			return {
				...state,
				steps: updateStep( state.steps, action.index, ( step ) => ( {
					...step,
					conditions: step.conditions.map( ( condition, i ) =>
						i === action.ci
							? { ...condition, [ action.key ]: action.value }
							: condition
					),
				} ) ),
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
