import { createApi } from './api';

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
} );
