import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FieldControl } from './FieldControl';

/*
 * The condition gates on one step. Each gate is a catalog condition with its params;
 * the picker below adds another. Locked conditions (the paid conditional-logic gates)
 * are offered disabled with an upgrade hint — the write-side validator and PlanGate are
 * the real enforcement, this only shapes the UI.
 */
export function GateEditor( { step, index, conditions, dispatch } ) {
	const descriptorFor = ( type ) =>
		( conditions || [] ).find( ( condition ) => condition.type === type );

	return (
		<div className="cartquill-builder__gates">
			<h4>{ __( 'Gates', 'cartquill' ) }</h4>
			{ 0 === step.conditions.length && (
				<p className="cartquill-builder__muted">
					{ __( 'No gates — this step always runs.', 'cartquill' ) }
				</p>
			) }
			<ul className="cartquill-builder__gate-list">
				{ step.conditions.map( ( condition, ci ) => {
					const descriptor = descriptorFor( condition.type );
					return (
						<li key={ ci } className="cartquill-builder__gate">
							<div className="cartquill-builder__gate-head">
								<span>
									{ descriptor
										? descriptor.label
										: condition.type }
								</span>
								<button
									type="button"
									className="button-link"
									onClick={ () =>
										dispatch( {
											type: 'removeCondition',
											index,
											ci,
										} )
									}
								>
									{ __( 'Remove', 'cartquill' ) }
								</button>
							</div>
							{ descriptor &&
								( descriptor.params || [] ).map( ( param ) => (
									<FieldControl
										key={ param.key }
										idPrefix={ `step-${ index }-cond-${ ci }` }
										field={ param }
										value={ condition[ param.key ] }
										onChange={ ( value ) =>
											dispatch( {
												type: 'setConditionParam',
												index,
												ci,
												key: param.key,
												value,
											} )
										}
									/>
								) ) }
						</li>
					);
				} ) }
			</ul>
			<AddGate
				index={ index }
				conditions={ conditions }
				dispatch={ dispatch }
			/>
		</div>
	);
}

/*
 * The picker for adding a gate: a select of the available conditions plus an add button.
 * When some conditions are locked behind a plan, a hint names the upgrade path.
 */
function AddGate( { index, conditions, dispatch } ) {
	const [ selected, setSelected ] = useState( '' );
	const available = ( conditions || [] ).filter(
		( condition ) => condition.available
	);
	const hasLocked = ( conditions || [] ).some(
		( condition ) => ! condition.available
	);

	const add = () => {
		const descriptor = available.find(
			( condition ) => condition.type === selected
		);
		if ( descriptor ) {
			dispatch( { type: 'addCondition', index, descriptor } );
			setSelected( '' );
		}
	};

	return (
		<div className="cartquill-builder__add-gate">
			<select
				value={ selected }
				onChange={ ( event ) => setSelected( event.target.value ) }
			>
				<option value="">{ __( 'Add a gate…', 'cartquill' ) }</option>
				{ available.map( ( condition ) => (
					<option key={ condition.type } value={ condition.type }>
						{ condition.label }
					</option>
				) ) }
			</select>
			<button
				type="button"
				className="button"
				disabled={ '' === selected }
				onClick={ add }
			>
				{ __( 'Add', 'cartquill' ) }
			</button>
			{ hasLocked && (
				<p className="cartquill-builder__muted">
					{ __( 'Some gates need a higher plan.', 'cartquill' ) }
				</p>
			) }
		</div>
	);
}
