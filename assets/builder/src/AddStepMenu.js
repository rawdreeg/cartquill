import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/*
 * The action picker for appending a step. Every action CartQuill ships is available.
 * An action contributed by an extension can report itself as not-yet-usable — for
 * example an integration whose account is not connected — and is then shown disabled
 * with the reason. Selecting an available action dispatches addStep with the catalog
 * descriptor, which seeds the new step's default config.
 */
function lockLabel( reason ) {
	if ( 'requires_connection' === reason ) {
		return __( 'Connect first', 'cartquill' );
	}
	return __( 'Unavailable', 'cartquill' );
}

export function AddStepMenu( { actions, dispatch } ) {
	const [ open, setOpen ] = useState( false );

	if ( ! open ) {
		return (
			<button
				type="button"
				className="button"
				onClick={ () => setOpen( true ) }
			>
				{ __( '+ Add step', 'cartquill' ) }
			</button>
		);
	}

	return (
		<div className="cartquill-builder__add-menu">
			<ul>
				{ ( actions || [] ).map( ( action ) => (
					<li
						key={ action.type }
						className="cartquill-builder__add-item"
					>
						<button
							type="button"
							className="button"
							disabled={ ! action.available }
							onClick={ () => {
								dispatch( {
									type: 'addStep',
									descriptor: action,
								} );
								setOpen( false );
							} }
						>
							{ action.label }
						</button>
						{ ! action.available && (
							<span className="cartquill-builder__lock">
								{ lockLabel( action.lock_reason ) }
							</span>
						) }
					</li>
				) ) }
			</ul>
			<button
				type="button"
				className="button-link"
				onClick={ () => setOpen( false ) }
			>
				{ __( 'Cancel', 'cartquill' ) }
			</button>
		</div>
	);
}
