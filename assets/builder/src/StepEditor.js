import { __ } from '@wordpress/i18n';
import { AiRewrite } from './AiRewrite';
import { FieldControl } from './FieldControl';
import { GateEditor } from './GateEditor';

/*
 * The expanded editor for one step: the delay before it runs, the descriptor-driven
 * fields for its action, and its condition gates. Every edit reports through dispatch.
 * The field list comes from the catalog action, so a new channel authors itself with
 * no bespoke form. An action the catalog no longer offers still shows its delay + gates.
 * When the AI add-on advertises its rewrite feature, an email step also gets a
 * "Rewrite with AI" control for its body.
 */
export function StepEditor( {
	step,
	index,
	catalog,
	mergeTags,
	api,
	aiRewrite,
	dispatch,
} ) {
	const action = ( catalog.actions || [] ).find(
		( candidate ) => candidate.type === step.action
	);
	const fields = action ? action.fields || [] : [];
	const showAiRewrite = aiRewrite && 'email' === step.action;

	return (
		<div className="cartquill-builder__editor">
			<FieldControl
				idPrefix={ `step-${ index }` }
				field={ {
					key: 'delay',
					label: __( 'Wait before this step (seconds)', 'cartquill' ),
					type: 'number',
				} }
				value={ step.delay }
				onChange={ ( value ) =>
					dispatch( { type: 'setStepDelay', index, delay: value } )
				}
			/>
			{ fields.map( ( field ) => (
				<FieldControl
					key={ field.key }
					idPrefix={ `step-${ index }` }
					field={ field }
					value={ step.config[ field.key ] }
					mergeTags={ mergeTags }
					onChange={ ( value ) =>
						dispatch( {
							type: 'setStepConfig',
							index,
							key: field.key,
							value,
						} )
					}
				/>
			) ) }
			{ showAiRewrite && (
				<AiRewrite
					api={ api }
					idPrefix={ `step-${ index }` }
					copy={ step.config.body || '' }
					onApply={ ( value ) =>
						dispatch( {
							type: 'setStepConfig',
							index,
							key: 'body',
							value,
						} )
					}
				/>
			) }
			<GateEditor
				step={ step }
				index={ index }
				conditions={ catalog.conditions }
				dispatch={ dispatch }
			/>
		</div>
	);
}
