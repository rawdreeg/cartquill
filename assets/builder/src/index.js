import { createRoot } from '@wordpress/element';
import { FlowBuilder } from './FlowBuilder';
import { createApi } from './api';

/**
 * Mount the builder into the container the admin page renders, using the REST
 * root + nonce + flow id localized as `cartquillBuilder`.
 */
const container = document.getElementById( 'cartquill-flow-builder' );
if ( container ) {
	const config = window.cartquillBuilder || {};
	createRoot( container ).render(
		<FlowBuilder
			api={ createApi( config ) }
			flowId={ config.flowId || 0 }
		/>
	);
}
