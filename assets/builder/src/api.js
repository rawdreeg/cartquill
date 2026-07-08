/*
 * Thin REST client for the flow builder, talking to the cartquill/v1 namespace.
 * Uses the REST root + nonce localized alongside the bundle; the nonce satisfies
 * WordPress's cookie-auth check for the capability-gated routes.
 */
export function createApi( { root = '', nonce = '' } = {} ) {
	const request = ( path, options = {} ) =>
		window
			.fetch( root + path, {
				credentials: 'same-origin',
				...options,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
					...( options.headers || {} ),
				},
			} )
			.then( ( response ) => response.json() );

	return {
		getCatalog: () => request( 'cartquill/v1/catalog' ),
		listFlows: () => request( 'cartquill/v1/flows' ),
		getFlow: ( id ) => request( `cartquill/v1/flows/${ id }` ),
		createFlow: ( data ) =>
			request( 'cartquill/v1/flows', {
				method: 'POST',
				body: JSON.stringify( data ),
			} ),
		saveFlow: ( id, data ) =>
			request( `cartquill/v1/flows/${ id }`, {
				method: 'PUT',
				body: JSON.stringify( data ),
			} ),
	};
}
