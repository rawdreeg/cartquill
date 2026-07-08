<?php
/**
 * REST read API for the flow builder: the catalog and stored flows.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Builder\BuilderCatalog;
use CartQuill\Builder\FlowSerializer;
use CartQuill\Builder\FlowValidator;
use CartQuill\Flow\FlowStep;
use CartQuill\Licensing\PlanGate;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\FlowRepository;

/**
 * The plugin's first REST surface (namespace `cartquill/v1`). It backs the React
 * builder: `GET /catalog` returns the triggers/actions/conditions metadata, and
 * `GET /flows` + `GET /flows/{id}` load flows in the uniform builder shape.
 *
 * Every route requires the `manage_options` capability. WordPress validates the
 * REST cookie nonce (`X-WP-Nonce`) before an authenticated capability check can
 * pass, so the admin page localizes a `wp_rest` nonce with the bundle.
 *
 * The request-shaping (WordPress) layer is a thin wrapper over the pure
 * {@see self::catalog_data()} / {@see self::flow_list()} / {@see self::flow_data()}
 * methods, which are exercised directly in tests without a WordPress runtime.
 */
final class FlowBuilderController {

	public const NAMESPACE = 'cartquill/v1';

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly BuilderCatalog $catalog,
		private readonly FlowSerializer $serializer,
		private readonly FlowValidator $validator,
		private readonly PlanGate $plan_gate,
	) {}

	/**
	 * Register the routes. The composition root calls this on `rest_api_init` (see
	 * Plugin::register), so the catalog is only assembled for REST requests.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'can_manage' );

		\register_rest_route(
			self::NAMESPACE,
			'/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_catalog' ),
				'permission_callback' => $permission,
			)
		);
		\register_rest_route(
			self::NAMESPACE,
			'/flows',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_flows' ),
				'permission_callback' => $permission,
			)
		);
		\register_rest_route(
			self::NAMESPACE,
			'/flows',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_create' ),
				'permission_callback' => $permission,
			)
		);
		\register_rest_route(
			self::NAMESPACE,
			'/flows/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_flow' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id' => array(
						'validate_callback' => static fn( $value ) => is_numeric( $value ),
					),
				),
			)
		);
		\register_rest_route(
			self::NAMESPACE,
			'/flows/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'handle_update' ),
				'permission_callback' => $permission,
			)
		);
	}

	public function can_manage(): bool {
		return \current_user_can( 'manage_options' );
	}

	// --- Pure data methods (WordPress-free; tested directly) ---------------------

	/**
	 * @return array<string, mixed>
	 */
	public function catalog_data(): array {
		return $this->catalog->to_array();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function flow_list(): array {
		return array_map( array( $this->serializer, 'summarize' ), $this->flows->all() );
	}

	/**
	 * The full flow, or null when no flow has that id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function flow_data( int $id ): ?array {
		$flow = $this->flows->find( $id );
		return null === $flow ? null : $this->serializer->serialize( $flow );
	}

	/**
	 * Create a flow from a builder payload.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed> a result with a `status` (200/400) plus `flow`+`plan_blocked` or `errors`
	 */
	public function create_flow( array $payload ): array {
		return $this->write( $payload, null );
	}

	/**
	 * Save changes to an existing flow.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed> a result with a `status` (200/400/404)
	 */
	public function update_flow( int $id, array $payload ): array {
		$existing = $this->flows->find( $id );
		if ( null === $existing ) {
			return array( 'status' => 404 );
		}
		return $this->write( $payload, $existing );
	}

	/**
	 * Validate → build → gate → persist. A payload the catalog rejects returns 400
	 * with per-field errors and is never saved. A save that asks to activate a flow
	 * the plan may not run active is persisted at a safe status and reports the block
	 * reason in `plan_blocked` (the edits are kept — matching the admin editor).
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed>
	 */
	private function write( array $payload, ?FlowRecord $existing ): array {
		$errors = $this->validator->validate( $payload );
		if ( array() !== $errors ) {
			return array(
				'status' => 400,
				'errors' => $errors,
			);
		}

		$source    = null !== $existing ? $existing->source : FlowRecord::SOURCE_BUILDER;
		$candidate = $this->validator->to_record( $payload, $existing?->id, $source, $existing?->created_at );
		$gated     = $this->plan_gate->enforce( $candidate, $existing );
		$saved     = $this->flows->save( $gated['record'] );

		return array(
			'status'       => 200,
			'flow'         => $this->serializer->serialize( $saved ),
			'plan_blocked' => $gated['blocked'],
		);
	}

	// --- WordPress request wrappers ---------------------------------------------

	public function handle_catalog(): \WP_REST_Response {
		return \rest_ensure_response( $this->catalog_data() );
	}

	public function handle_flows(): \WP_REST_Response {
		return \rest_ensure_response( $this->flow_list() );
	}

	/**
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_flow( \WP_REST_Request $request ) {
		$data = $this->flow_data( (int) $request['id'] );
		if ( null === $data ) {
			return new \WP_Error( 'cartquill_flow_not_found', \__( 'Flow not found.', 'cartquill' ), array( 'status' => 404 ) );
		}
		return \rest_ensure_response( $data );
	}

	/**
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_create( \WP_REST_Request $request ) {
		return $this->respond( $this->create_flow( $this->sanitize_payload( (array) $request->get_json_params() ) ) );
	}

	/**
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update( \WP_REST_Request $request ) {
		return $this->respond( $this->update_flow( (int) $request['id'], $this->sanitize_payload( (array) $request->get_json_params() ) ) );
	}

	/**
	 * Sanitize a raw builder payload before it is validated and stored — the REST
	 * boundary's equivalent of the admin editor's `posted_steps()`. Field types come
	 * from the catalog: an `html` field (the email body) runs through wp_kses_post, a
	 * `textarea` through sanitize_textarea_field (preserving newlines), and everything
	 * else through sanitize_text_field.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_payload( array $payload ): array {
		$payload['name'] = \sanitize_text_field( (string) ( $payload['name'] ?? '' ) );

		$types = $this->action_field_types();
		$steps = array();
		foreach ( array_values( (array) ( $payload['steps'] ?? array() ) ) as $step ) {
			$steps[] = $this->sanitize_step( (array) $step, $types );
		}
		$payload['steps'] = $steps;

		return $payload;
	}

	/**
	 * @param array<string, array<string, string>> $types action type => (field key => field type)
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize_step( array $step, array $types ): array {
		$field_types = $types[ (string) ( $step['action'] ?? FlowStep::ACTION_EMAIL ) ] ?? array();

		$config = array();
		foreach ( (array) ( $step['config'] ?? array() ) as $key => $value ) {
			$config[ $key ] = $this->sanitize_value( $value, $field_types[ (string) $key ] ?? 'text' );
		}
		$step['config'] = $config;

		$conditions = array();
		foreach ( (array) ( $step['conditions'] ?? array() ) as $condition ) {
			$clean = array();
			foreach ( (array) $condition as $key => $value ) {
				$clean[ $key ] = is_array( $value ) ? array_map( array( $this, 'sanitize_scalar' ), $value ) : $this->sanitize_scalar( $value );
			}
			$conditions[] = $clean;
		}
		$step['conditions'] = $conditions;

		return $step;
	}

	private function sanitize_value( mixed $value, string $type ): mixed {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'sanitize_scalar' ), $value );
		}
		$string = (string) $value;
		return match ( $type ) {
			'html'     => \wp_kses_post( $string ),
			'textarea' => \sanitize_textarea_field( $string ),
			default    => \sanitize_text_field( $string ),
		};
	}

	private function sanitize_scalar( mixed $value ): string {
		return \sanitize_text_field( (string) $value );
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function action_field_types(): array {
		$types = array();
		foreach ( $this->catalog->actions() as $action ) {
			$map = array();
			foreach ( (array) ( $action['fields'] ?? array() ) as $field ) {
				$map[ (string) $field['key'] ] = (string) ( $field['type'] ?? 'text' );
			}
			$types[ (string) $action['type'] ] = $map;
		}
		return $types;
	}

	/**
	 * Map a write result to a REST response.
	 *
	 * @param array<string, mixed> $result
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function respond( array $result ) {
		$status = (int) ( $result['status'] ?? 200 );

		if ( 400 === $status ) {
			return new \WP_Error(
				'cartquill_invalid_flow',
				\__( 'The flow could not be saved.', 'cartquill' ),
				array(
					'status' => 400,
					'errors' => $result['errors'] ?? array(),
				)
			);
		}
		if ( 404 === $status ) {
			return new \WP_Error( 'cartquill_flow_not_found', \__( 'Flow not found.', 'cartquill' ), array( 'status' => 404 ) );
		}

		return \rest_ensure_response(
			array(
				'flow'         => $result['flow'] ?? null,
				'plan_blocked' => $result['plan_blocked'] ?? '',
			)
		);
	}
}
