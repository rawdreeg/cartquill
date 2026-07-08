import {
	arrayMove,
	buildPayload,
	defaultCondition,
	defaultConfig,
	flowReducer,
	initialFlowState,
	withKeys,
} from './reducer';

const slackDescriptor = {
	type: 'slack_post',
	label: 'Post to Slack',
	fields: [
		{ key: 'channel', type: 'text', default: '' },
		{ key: 'text', type: 'textarea', default: 'Hello' },
		{ key: 'columns', type: 'list', default: '' },
	],
};

const cartValueCondition = {
	type: 'cart_value_gt',
	label: 'Cart value greater than',
	params: [ { key: 'value', type: 'number', default: '' } ],
};

const flow = {
	id: 7,
	name: 'Welcome series',
	type: 'welcome',
	status: 'active',
	source: 'builder',
	steps: [
		{
			delay: 0,
			action: 'email',
			config: { subject: 'Hi', body: 'A' },
			conditions: [],
		},
		{
			delay: 3600,
			action: 'slack_post',
			config: { channel: '#a', text: 'B' },
			conditions: [],
		},
	],
};

describe( 'arrayMove', () => {
	it( 'moves an item without mutating the input', () => {
		const list = [ 'a', 'b', 'c' ];
		const moved = arrayMove( list, 0, 2 );
		expect( moved ).toEqual( [ 'b', 'c', 'a' ] );
		expect( list ).toEqual( [ 'a', 'b', 'c' ] );
	} );
} );

describe( 'withKeys', () => {
	it( 'assigns a unique stable _key to every step', () => {
		const keyed = withKeys( flow.steps );
		expect( keyed ).toHaveLength( 2 );
		expect( keyed[ 0 ]._key ).toBeTruthy();
		expect( keyed[ 1 ]._key ).toBeTruthy();
		expect( keyed[ 0 ]._key ).not.toBe( keyed[ 1 ]._key );
	} );

	it( 'tolerates a missing step list', () => {
		expect( withKeys( undefined ) ).toEqual( [] );
	} );
} );

describe( 'initialFlowState', () => {
	it( 'seeds editable state from a loaded flow, not dirty', () => {
		const state = initialFlowState( flow );
		expect( state.id ).toBe( 7 );
		expect( state.name ).toBe( 'Welcome series' );
		expect( state.type ).toBe( 'welcome' );
		expect( state.status ).toBe( 'active' );
		expect( state.steps ).toHaveLength( 2 );
		expect( state.dirty ).toBe( false );
	} );

	it( 'produces a blank new-flow state from null', () => {
		const state = initialFlowState( null );
		expect( state.id ).toBe( 0 );
		expect( state.name ).toBe( '' );
		expect( state.type ).toBe( '' );
		expect( state.status ).toBe( 'draft' );
		expect( state.steps ).toEqual( [] );
		expect( state.dirty ).toBe( false );
	} );
} );

describe( 'flowReducer', () => {
	const start = () => initialFlowState( flow );

	it( 'sets the name and marks dirty', () => {
		const next = flowReducer( start(), {
			type: 'setName',
			name: 'Renamed',
		} );
		expect( next.name ).toBe( 'Renamed' );
		expect( next.dirty ).toBe( true );
	} );

	it( 'sets the status and marks dirty', () => {
		const next = flowReducer( start(), {
			type: 'setStatus',
			status: 'paused',
		} );
		expect( next.status ).toBe( 'paused' );
		expect( next.dirty ).toBe( true );
	} );

	it( 'reorders steps, preserves their keys, and marks dirty', () => {
		const before = start();
		const keys = before.steps.map( ( step ) => step._key );
		const next = flowReducer( before, {
			type: 'moveStep',
			from: 0,
			to: 1,
		} );
		expect( next.steps.map( ( step ) => step.action ) ).toEqual( [
			'slack_post',
			'email',
		] );
		expect( next.steps.map( ( step ) => step._key ) ).toEqual( [
			keys[ 1 ],
			keys[ 0 ],
		] );
		expect( next.dirty ).toBe( true );
	} );

	it( 'resets to a clean state on save with the server flow', () => {
		const dirty = flowReducer( start(), { type: 'setName', name: 'X' } );
		expect( dirty.dirty ).toBe( true );
		const saved = flowReducer( dirty, { type: 'saved', flow } );
		expect( saved.name ).toBe( 'Welcome series' );
		expect( saved.dirty ).toBe( false );
	} );

	it( 'replaces state on load', () => {
		const loaded = flowReducer( initialFlowState( null ), {
			type: 'load',
			flow,
		} );
		expect( loaded.name ).toBe( 'Welcome series' );
		expect( loaded.steps ).toHaveLength( 2 );
		expect( loaded.dirty ).toBe( false );
	} );

	it( 'ignores unknown actions', () => {
		const state = start();
		expect( flowReducer( state, { type: 'nope' } ) ).toBe( state );
	} );
} );

describe( 'buildPayload', () => {
	it( 'strips the _key from every step and keeps the wire shape', () => {
		const payload = buildPayload( initialFlowState( flow ) );
		expect( payload ).toEqual( {
			name: 'Welcome series',
			type: 'welcome',
			status: 'active',
			steps: [
				{
					delay: 0,
					action: 'email',
					config: { subject: 'Hi', body: 'A' },
					conditions: [],
				},
				{
					delay: 3600,
					action: 'slack_post',
					config: { channel: '#a', text: 'B' },
					conditions: [],
				},
			],
		} );
		payload.steps.forEach( ( step ) =>
			expect( step._key ).toBeUndefined()
		);
	} );
} );

describe( 'defaultConfig', () => {
	it( 'seeds each field to its default, with list fields as arrays', () => {
		expect( defaultConfig( slackDescriptor.fields ) ).toEqual( {
			channel: '',
			text: 'Hello',
			columns: [],
		} );
	} );

	it( 'tolerates an action with no fields', () => {
		expect( defaultConfig( undefined ) ).toEqual( {} );
	} );
} );

describe( 'defaultCondition', () => {
	it( 'seeds a condition with its type and param defaults', () => {
		expect( defaultCondition( cartValueCondition ) ).toEqual( {
			type: 'cart_value_gt',
			value: '',
		} );
	} );

	it( 'produces a bare gate for a param-less condition', () => {
		expect(
			defaultCondition( { type: 'first_time_customer', params: [] } )
		).toEqual( { type: 'first_time_customer' } );
	} );
} );

describe( 'flowReducer — step editing', () => {
	const start = () => initialFlowState( flow );

	it( 'appends a new step from a descriptor with a fresh key', () => {
		const before = start();
		const next = flowReducer( before, {
			type: 'addStep',
			descriptor: slackDescriptor,
		} );
		expect( next.steps ).toHaveLength( 3 );
		const added = next.steps[ 2 ];
		expect( added.action ).toBe( 'slack_post' );
		expect( added.delay ).toBe( 0 );
		expect( added.conditions ).toEqual( [] );
		expect( added.config ).toEqual( {
			channel: '',
			text: 'Hello',
			columns: [],
		} );
		expect( added._key ).toBeTruthy();
		expect(
			next.steps.filter( ( step ) => step._key === added._key )
		).toHaveLength( 1 );
		expect( next.dirty ).toBe( true );
	} );

	it( 'removes the step at an index', () => {
		const next = flowReducer( start(), { type: 'removeStep', index: 0 } );
		expect( next.steps ).toHaveLength( 1 );
		expect( next.steps[ 0 ].action ).toBe( 'slack_post' );
		expect( next.dirty ).toBe( true );
	} );

	it( 'sets a step delay, coercing to a non-negative integer', () => {
		expect(
			flowReducer( start(), {
				type: 'setStepDelay',
				index: 0,
				delay: '7200',
			} ).steps[ 0 ].delay
		).toBe( 7200 );
		expect(
			flowReducer( start(), {
				type: 'setStepDelay',
				index: 0,
				delay: '',
			} ).steps[ 0 ].delay
		).toBe( 0 );
		expect(
			flowReducer( start(), {
				type: 'setStepDelay',
				index: 0,
				delay: -5,
			} ).steps[ 0 ].delay
		).toBe( 0 );
	} );

	it( 'sets a single config key without touching siblings', () => {
		const next = flowReducer( start(), {
			type: 'setStepConfig',
			index: 0,
			key: 'subject',
			value: 'New subject',
		} );
		expect( next.steps[ 0 ].config ).toEqual( {
			subject: 'New subject',
			body: 'A',
		} );
		expect( next.steps[ 1 ].config ).toEqual( flow.steps[ 1 ].config );
		expect( next.dirty ).toBe( true );
	} );
} );

describe( 'flowReducer — gate editing', () => {
	const start = () => initialFlowState( flow );

	it( 'adds a condition seeded from its descriptor', () => {
		const next = flowReducer( start(), {
			type: 'addCondition',
			index: 1,
			descriptor: cartValueCondition,
		} );
		expect( next.steps[ 1 ].conditions ).toEqual( [
			{ type: 'cart_value_gt', value: '' },
		] );
		expect( next.steps[ 0 ].conditions ).toEqual( [] );
		expect( next.dirty ).toBe( true );
	} );

	it( 'sets a condition param and removes a condition by index', () => {
		const withGate = flowReducer( start(), {
			type: 'addCondition',
			index: 0,
			descriptor: cartValueCondition,
		} );
		const set = flowReducer( withGate, {
			type: 'setConditionParam',
			index: 0,
			ci: 0,
			key: 'value',
			value: '50',
		} );
		expect( set.steps[ 0 ].conditions[ 0 ] ).toEqual( {
			type: 'cart_value_gt',
			value: '50',
		} );
		const removed = flowReducer( set, {
			type: 'removeCondition',
			index: 0,
			ci: 0,
		} );
		expect( removed.steps[ 0 ].conditions ).toEqual( [] );
		expect( removed.dirty ).toBe( true );
	} );
} );
