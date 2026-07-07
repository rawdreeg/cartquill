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
}
