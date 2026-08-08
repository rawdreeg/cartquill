import { createApi, isErrorResponse } from './api';

describe( 'isErrorResponse', () => {
	it( 'treats a WP_Error body (has a code) as an error', () => {
		expect(
			isErrorResponse( {
				code: 'cartquill_flow_not_found',
				message: 'Flow not found.',
				data: { status: 404 },
			} )
		).toBe( true );
	} );

	it( 'treats a normal flow/catalog body as data', () => {
		expect( isErrorResponse( { id: 7, name: 'Welcome' } ) ).toBe( false );
		expect( isErrorResponse( {} ) ).toBe( false );
		expect( isErrorResponse( null ) ).toBe( false );
		expect( isErrorResponse( undefined ) ).toBe( false );
	} );
} );

describe( 'builder api', () => {
	beforeEach( () => {
		global.fetch = jest.fn( () =>
			Promise.resolve( { json: () => Promise.resolve( {} ) } )
		);
	} );

	it( 'requests the catalog from the namespaced route with the nonce header', () => {
		createApi( {
			root: 'https://example.test/wp-json/',
			nonce: 'abc123',
		} ).getCatalog();

		expect( global.fetch ).toHaveBeenCalledWith(
			'https://example.test/wp-json/cartquill/v1/catalog',
			expect.objectContaining( {
				headers: expect.objectContaining( { 'X-WP-Nonce': 'abc123' } ),
			} )
		);
	} );

	it( 'loads a flow by id', () => {
		createApi( {
			root: 'https://example.test/wp-json/',
			nonce: 'abc123',
		} ).getFlow( 7 );

		expect( global.fetch ).toHaveBeenCalledWith(
			'https://example.test/wp-json/cartquill/v1/flows/7',
			expect.anything()
		);
	} );

	it( 'PUTs a flow by id with a JSON body', () => {
		createApi( {
			root: 'https://example.test/wp-json/',
			nonce: 'abc123',
		} ).saveFlow( 5, { name: 'A' } );

		expect( global.fetch ).toHaveBeenCalledWith(
			'https://example.test/wp-json/cartquill/v1/flows/5',
			expect.objectContaining( {
				method: 'PUT',
				body: JSON.stringify( { name: 'A' } ),
			} )
		);
	} );

	it( 'exposes a raw request helper an extension can call its own route with', () => {
		createApi( {
			root: 'https://example.test/wp-json/',
			nonce: 'abc123',
		} ).request( 'cartquill/v1/anything', {
			method: 'POST',
			body: JSON.stringify( { hello: true } ),
		} );

		expect( global.fetch ).toHaveBeenCalledWith(
			'https://example.test/wp-json/cartquill/v1/anything',
			expect.objectContaining( {
				method: 'POST',
				body: JSON.stringify( { hello: true } ),
				headers: expect.objectContaining( { 'X-WP-Nonce': 'abc123' } ),
			} )
		);
	} );
} );
