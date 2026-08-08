import { createRoot } from '@wordpress/element';
import { FlowBuilder } from './FlowBuilder';
import { createApi } from './api';
import './style.css';

/**
 * Mount the builder into the container the admin page renders, using the REST
 * root + nonce + flow id localized as `cartquillBuilder`.
 *
 * Mounting waits for DOMContentLoaded so that any extension script enqueued
 * alongside this bundle has already run, whatever order WordPress printed them
 * in — see `slots.js`.
 */
function mount() {
	const container = document.getElementById( 'cartquill-flow-builder' );
	if ( ! container ) {
		return;
	}
	const config = window.cartquillBuilder || {};
	createRoot( container ).render(
		<FlowBuilder
			api={ createApi( config ) }
			flowId={ config.flowId || 0 }
		/>
	);
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
