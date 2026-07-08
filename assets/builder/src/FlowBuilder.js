import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/*
 * The read-only builder shell: it loads the catalog and (if editing) a flow from
 * the REST API and renders the flow's steps. Interactivity — drag-to-reorder, the
 * step editors, saving — arrives in the next slice; this proves the whole pipeline
 * (build → enqueue → REST → render) end to end.
 */
export function FlowBuilder( { api, flowId } ) {
	const [ catalog, setCatalog ] = useState( null );
	const [ flow, setFlow ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let active = true;
		api.getCatalog()
			.then( ( data ) => active && setCatalog( data ) )
			.catch(
				() =>
					active &&
					setError(
						__( 'Could not load the builder catalog.', 'cartquill' )
					)
			);
		if ( flowId ) {
			api.getFlow( flowId )
				.then( ( data ) => active && setFlow( data ) )
				.catch(
					() =>
						active &&
						setError(
							__( 'Could not load the flow.', 'cartquill' )
						)
				);
		}
		return () => {
			active = false;
		};
	}, [ api, flowId ] );

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>{ error }</p>
			</div>
		);
	}

	if ( ! catalog || ( flowId && ! flow ) ) {
		return <p>{ __( 'Loading…', 'cartquill' ) }</p>;
	}

	const actionLabel = ( type ) => {
		const found = ( catalog.actions || [] ).find(
			( action ) => action.type === type
		);
		return found ? found.label : type;
	};

	return (
		<div className="cartquill-builder">
			<h2 className="cartquill-builder__name">
				{ flow ? flow.name : __( 'New flow', 'cartquill' ) }
			</h2>
			{ flow && (
				<ol className="cartquill-builder__steps">
					{ flow.steps.map( ( step, index ) => (
						<li key={ index } className="cartquill-builder__step">
							<span className="cartquill-builder__step-action">
								{ actionLabel( step.action ) }
							</span>
							{ step.delay > 0 && (
								<span className="cartquill-builder__step-delay">
									{ ` · +${ step.delay }s` }
								</span>
							) }
						</li>
					) ) }
				</ol>
			) }
		</div>
	);
}
