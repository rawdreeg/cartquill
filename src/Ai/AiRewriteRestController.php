<?php
/**
 * REST endpoint for per-step "rewrite with AI" in the flow builder.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Varies a single piece of copy through the AI proxy for the React builder, so the
 * per-step rewrite feature lives in the builder rather than the retired classic editor.
 * It reuses {@see AiFlowGenerator::rewrite()} (which enforces the AI plan, the rate
 * limit, and proxy-error tolerance) and adds the same external-service disclosure gate
 * the classic editor applied: no copy reaches the proxy until the disclosure is
 * acknowledged. Rewritten copy is returned to the builder for review — never persisted
 * or sent here.
 *
 * Registered only by the AI add-on when the AI plan is active (the free build never ships
 * this class), so, like the other AI wiring, it is instantiated from `AiAddon::boot()` and
 * never referenced by any free-shipped file.
 *
 * The pure {@see self::rewrite_copy()} carries the logic and is unit-tested without a
 * WordPress runtime; {@see self::handle_rewrite()} is the thin request-shaping wrapper.
 */
final class AiRewriteRestController {

	public const NAMESPACE = 'cartquill/v1';

	public function __construct(
		private readonly AiFlowGenerator $generator,
		private readonly AiDisclosure $disclosure,
	) {}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/ai/rewrite',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rewrite' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function can_manage(): bool {
		return \current_user_can( 'manage_options' );
	}

	/**
	 * Vary a piece of copy, or explain why it cannot. Returns a status-tagged result the
	 * WordPress wrapper maps to a response: 200 with the rewritten copy; 400 for empty
	 * copy; 403 with the disclosure to acknowledge; 422 when rewriting is unavailable
	 * (unlicensed, rate-limited, or the proxy failed). Acknowledging inline (a truthy
	 * `acknowledge`) records consent, then proceeds.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed>
	 */
	public function rewrite_copy( array $payload ): array {
		$copy        = (string) ( $payload['copy'] ?? '' );
		$instruction = (string) ( $payload['instruction'] ?? '' );

		if ( '' === trim( $copy ) ) {
			return array(
				'status'  => 400,
				'code'    => 'cartquill_ai_empty',
				'message' => \__( 'There is no copy to rewrite yet.', 'cartquill' ),
			);
		}

		if ( ! $this->disclosure->is_acknowledged() ) {
			if ( empty( $payload['acknowledge'] ) ) {
				return array(
					'status'     => 403,
					'code'       => 'cartquill_ai_disclosure',
					'message'    => \__( 'Acknowledge the AI service disclosure to continue.', 'cartquill' ),
					'disclosure' => array(
						'summary'     => $this->disclosure->summary(),
						'terms_url'   => AiDisclosure::TERMS_URL,
						'privacy_url' => AiDisclosure::PRIVACY_URL,
					),
				);
			}
			$this->disclosure->acknowledge();
		}

		$rewritten = $this->generator->rewrite( $copy, $instruction );
		if ( null === $rewritten ) {
			return array(
				'status'  => 422,
				'code'    => 'cartquill_ai_unavailable',
				'message' => \__( 'AI rewriting is unavailable right now — check your plan or try again shortly.', 'cartquill' ),
			);
		}

		return array(
			'status' => 200,
			'copy'   => $rewritten,
		);
	}

	/**
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_rewrite( \WP_REST_Request $request ) {
		$params  = (array) $request->get_json_params();
		$payload = array(
			// The copy is often the email body (HTML allowed); keep markup, drop anything unsafe.
			'copy'        => \wp_kses_post( (string) ( $params['copy'] ?? '' ) ),
			'instruction' => \sanitize_text_field( (string) ( $params['instruction'] ?? '' ) ),
			'acknowledge' => ! empty( $params['acknowledge'] ),
		);

		return $this->respond( $this->rewrite_copy( $payload ) );
	}

	/**
	 * Map a rewrite result to a REST response.
	 *
	 * @param array<string, mixed> $result
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function respond( array $result ) {
		$status = (int) ( $result['status'] ?? 200 );

		if ( 200 === $status ) {
			return \rest_ensure_response( array( 'copy' => $result['copy'] ?? '' ) );
		}

		$data = array( 'status' => $status );
		if ( isset( $result['disclosure'] ) ) {
			$data['disclosure'] = $result['disclosure'];
		}

		// The result already carries the full wire code (e.g. cartquill_ai_disclosure) the
		// builder matches on, so the JS-facing contract is asserted at the pure-method seam.
		return new \WP_Error(
			(string) ( $result['code'] ?? 'cartquill_ai_error' ),
			(string) ( $result['message'] ?? \__( 'AI rewriting failed.', 'cartquill' ) ),
			$data
		);
	}
}
