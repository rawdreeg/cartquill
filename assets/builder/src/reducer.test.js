import {
	arrayMove,
	buildPayload,
	flowReducer,
	initialFlowState,
	withKeys,
} from './reducer';

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
