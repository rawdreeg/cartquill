import { useEffect, useReducer, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { StepList } from './StepList';
import { buildPayload, flowReducer, initialFlowState } from './reducer';

/*
 * The builder root. It loads the catalog and (when editing) a flow into reducer-backed
 * editable state, lets the user rename the flow, change its status, and drag steps into
 * a new order, then saves through the REST write API. A dirty flag drives both the Save
 * button and a navigation-away warning. Descriptor-driven field editing and adding steps
 * arrive in the next slice; this slice makes the flow itself editable end to end.
 */
function planBlockedMessage( reason ) {
	if ( 'workflow_cap' === reason ) {
		return __(
			'The active-workflow limit for your plan was reached, so the flow was saved but not activated.',
			'cartquill'
		);
	}
	if ( 'conditional_logic' === reason ) {
		return __(
			'Conditional logic needs a higher plan, so the flow was saved but not activated.',
			'cartquill'
		);
	}
	return __( 'The flow was saved but not activated.', 'cartquill' );
}

export function FlowBuilder( { api, flowId } ) {
	const [ catalog, setCatalog ] = useState( null );
	const [ phase, setPhase ] = useState( 'loading' );
	const [ state, dispatch ] = useReducer( flowReducer, null, () =>
		initialFlowState( null )
	);
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		let active = true;
		const loads = [
			api.getCatalog().then( ( data ) => {
				if ( active ) {
					setCatalog( data );
				}
			} ),
		];
		if ( flowId ) {
			loads.push(
				api.getFlow( flowId ).then( ( data ) => {
					if ( active ) {
						dispatch( { type: 'load', flow: data } );
					}
				} )
			);
		}
		Promise.all( loads )
			.then( () => active && setPhase( 'ready' ) )
			.catch( () => active && setPhase( 'error' ) );
		return () => {
			active = false;
		};
	}, [ api, flowId ] );

	useEffect( () => {
		if ( ! state.dirty ) {
			return undefined;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
			return '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ state.dirty ] );

	if ( 'error' === phase ) {
		return (
			<div className="notice notice-error">
				<p>{ __( 'Could not load the builder.', 'cartquill' ) }</p>
			</div>
		);
	}

	if ( 'loading' === phase || ! catalog ) {
		return <p>{ __( 'Loading…', 'cartquill' ) }</p>;
	}

	const actionLabel = ( type ) => {
		const found = ( catalog.actions || [] ).find(
			( action ) => action.type === type
		);
		return found ? found.label : type;
	};

	const save = () => {
		setSaving( true );
		setNotice( null );
		const payload = buildPayload( state );
		const request = state.id
			? api.saveFlow( state.id, payload )
			: api.createFlow( payload );
		request
			.then( ( result ) => {
				setSaving( false );
				if ( result && result.code ) {
					setNotice( {
						type: 'error',
						errors: result.data?.errors || [],
						text:
							result.message ||
							__( 'The flow could not be saved.', 'cartquill' ),
					} );
					return;
				}
				dispatch( { type: 'saved', flow: result.flow } );
				setNotice(
					result.plan_blocked
						? {
								type: 'warning',
								text: planBlockedMessage( result.plan_blocked ),
						  }
						: {
								type: 'success',
								text: __( 'Flow saved.', 'cartquill' ),
						  }
				);
			} )
			.catch( () => {
				setSaving( false );
				setNotice( {
					type: 'error',
					text: __( 'The flow could not be saved.', 'cartquill' ),
				} );
			} );
	};

	const statusOptions = [
		{ value: 'draft', label: __( 'Draft', 'cartquill' ) },
		{ value: 'active', label: __( 'Active', 'cartquill' ) },
		{ value: 'paused', label: __( 'Paused', 'cartquill' ) },
	];

	return (
		<div className="cartquill-builder">
			{ notice && (
				<div className={ `notice notice-${ notice.type }` }>
					<p>{ notice.text }</p>
					{ notice.errors && notice.errors.length > 0 && (
						<ul>
							{ notice.errors.map( ( item, index ) => (
								<li key={ index }>{ item.message }</li>
							) ) }
						</ul>
					) }
				</div>
			) }

			<div className="cartquill-builder__header">
				<div className="cartquill-builder__field">
					<label htmlFor="cartquill-flow-name">
						{ __( 'Flow name', 'cartquill' ) }
					</label>
					<input
						id="cartquill-flow-name"
						type="text"
						value={ state.name }
						onChange={ ( event ) =>
							dispatch( {
								type: 'setName',
								name: event.target.value,
							} )
						}
					/>
				</div>
				<div className="cartquill-builder__field">
					<label htmlFor="cartquill-flow-status">
						{ __( 'Status', 'cartquill' ) }
					</label>
					<select
						id="cartquill-flow-status"
						value={ state.status }
						onChange={ ( event ) =>
							dispatch( {
								type: 'setStatus',
								status: event.target.value,
							} )
						}
					>
						{ statusOptions.map( ( option ) => (
							<option key={ option.value } value={ option.value }>
								{ option.label }
							</option>
						) ) }
					</select>
				</div>
			</div>

			<StepList
				steps={ state.steps }
				actionLabel={ actionLabel }
				onMove={ ( from, to ) =>
					dispatch( { type: 'moveStep', from, to } )
				}
			/>

			<div className="cartquill-builder__actions">
				<button
					type="button"
					className="button button-primary"
					disabled={ saving || ! state.dirty }
					onClick={ save }
				>
					{ saving
						? __( 'Saving…', 'cartquill' )
						: __( 'Save flow', 'cartquill' ) }
				</button>
			</div>
		</div>
	);
}
