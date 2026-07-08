/*
 * Thin REST client for the flow builder, talking to the cartquill/v1 namespace.
 * Uses the REST root + nonce localized alongside the bundle; the nonce satisfies
 * WordPress's cookie-auth check for the capability-gated routes.
 *
 * The client resolves with the parsed body even for HTTP errors (it does not check
 * `response.ok`), because a WordPress `WP_Error` serializes to a useful JSON body —
 * `{ code, message, data: { status, errors } }` — that both the load and save paths
 * want to read. Callers MUST therefore guard every response with `isErrorResponse`
 * before treating it as data; a success body never carries a `code`.
 */

/**
 * Whether a resolved REST response is a WordPress error rather than data.
 *
 * @param {Object} result the parsed response body
 * @return {boolean} true when the body is a WP_Error
 */
export function isErrorResponse( result ) {
	return Boolean( result && result.code );
}

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
		rewriteCopy: ( copy, instruction, acknowledge = false ) =>
			request( 'cartquill/v1/ai/rewrite', {
				method: 'POST',
				body: JSON.stringify( { copy, instruction, acknowledge } ),
			} ),
	};
}
