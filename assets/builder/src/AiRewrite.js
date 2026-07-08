import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { isErrorResponse } from './api';

/*
 * Per-step "rewrite with AI" for the builder, replacing the same feature that used to live
 * in the classic editor. It sends the step's current copy plus an instruction to the AI
 * REST endpoint and applies the returned copy through onApply — the builder keeps the
 * result as an unsaved edit for review, never auto-sending. On first use the endpoint asks
 * for the external-service disclosure, which the control surfaces with an inline
 * "Agree and rewrite" that re-submits with acknowledgement.
 */
export function AiRewrite( { api, copy, onApply, idPrefix } ) {
	const [ instruction, setInstruction ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const id = `${ idPrefix }-ai-instruction`;

	const rewrite = ( acknowledge ) => {
		setBusy( true );
		setNotice( null );
		api.rewriteCopy( copy, instruction, acknowledge )
			.then( ( result ) => {
				setBusy( false );
				if ( isErrorResponse( result ) ) {
					if ( 'cartquill_ai_disclosure' === result.code ) {
						setNotice( {
							type: 'disclosure',
							disclosure: result.data?.disclosure,
						} );
						return;
					}
					setNotice( {
						type: 'error',
						text:
							result.message ||
							__( 'AI rewriting failed.', 'cartquill' ),
					} );
					return;
				}
				onApply( result.copy );
				setInstruction( '' );
			} )
			.catch( () => {
				setBusy( false );
				setNotice( {
					type: 'error',
					text: __( 'AI rewriting failed.', 'cartquill' ),
				} );
			} );
	};

	return (
		<div className="cartquill-builder__ai">
			<label htmlFor={ id }>
				{ __( 'Rewrite with AI', 'cartquill' ) }
			</label>
			<input
				id={ id }
				type="text"
				value={ instruction }
				placeholder={ __(
					'e.g. make it shorter and warmer',
					'cartquill'
				) }
				onChange={ ( event ) => setInstruction( event.target.value ) }
			/>
			<button
				type="button"
				className="button"
				disabled={ busy }
				onClick={ () => rewrite( false ) }
			>
				{ busy
					? __( 'Rewriting…', 'cartquill' )
					: __( 'Rewrite', 'cartquill' ) }
			</button>

			{ 'disclosure' === notice?.type && (
				<div className="notice notice-info inline">
					<p>{ notice.disclosure?.summary }</p>
					<p>
						<a
							href={ notice.disclosure?.terms_url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Terms of Service', 'cartquill' ) }
						</a>
						{ ' · ' }
						<a
							href={ notice.disclosure?.privacy_url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Privacy Policy', 'cartquill' ) }
						</a>
					</p>
					<button
						type="button"
						className="button"
						disabled={ busy }
						onClick={ () => rewrite( true ) }
					>
						{ __( 'Agree and rewrite', 'cartquill' ) }
					</button>
				</div>
			) }

			{ 'error' === notice?.type && (
				<p className="cartquill-builder__error">{ notice.text }</p>
			) }
		</div>
	);
}
