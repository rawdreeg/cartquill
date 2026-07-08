import {
	contextKeysFor,
	insertIntoList,
	insertIntoText,
	mergeToken,
} from './mergeTags';

const catalog = {
	triggers: [
		{
			type: 'order_alert',
			context_keys: [ 'order_total', 'customer_email' ],
		},
		{ type: 'welcome', context_keys: [ 'customer_email' ] },
	],
};

describe( 'mergeToken', () => {
	it( 'wraps a key in the renderer placeholder syntax', () => {
		expect( mergeToken( 'order_total' ) ).toBe( '{{ order_total }}' );
	} );
} );

describe( 'contextKeysFor', () => {
	it( 'returns the matching trigger context keys', () => {
		expect( contextKeysFor( catalog, 'order_alert' ) ).toEqual( [
			'order_total',
			'customer_email',
		] );
	} );

	it( 'returns [] for an unknown or missing type', () => {
		expect( contextKeysFor( catalog, 'nope' ) ).toEqual( [] );
		expect( contextKeysFor( {}, 'welcome' ) ).toEqual( [] );
	} );
} );

describe( 'insertIntoText', () => {
	it( 'inserts the token, spacing it from existing text', () => {
		expect( insertIntoText( '', 'customer_email' ) ).toBe(
			'{{ customer_email }}'
		);
		expect( insertIntoText( 'Hi', 'customer_email' ) ).toBe(
			'Hi {{ customer_email }}'
		);
		expect( insertIntoText( 'Hi ', 'customer_email' ) ).toBe(
			'Hi {{ customer_email }}'
		);
	} );
} );

describe( 'insertIntoList', () => {
	it( 'appends the token as a new list item, tolerating non-arrays', () => {
		expect( insertIntoList( [ '{{ order_id }}' ], 'total' ) ).toEqual( [
			'{{ order_id }}',
			'{{ total }}',
		] );
		expect( insertIntoList( undefined, 'total' ) ).toEqual( [
			'{{ total }}',
		] );
	} );
} );
