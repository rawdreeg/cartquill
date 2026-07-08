<?php
/**
 * Hosts the React flow builder: a hidden admin page that mounts the bundle.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Registers a hidden admin page whose body is a single mount point for the flow
 * builder React app, and enqueues the compiled bundle with the dependency manifest
 * @wordpress/scripts emits (`index.asset.php`). The REST root, a `wp_rest` nonce,
 * and the flow id are localized as `cartquillBuilder` for the app to read.
 */
final class FlowBuilderPage {

	public const SLUG = 'cartquill-flow-builder';

	private const HANDLE       = 'cartquill-flow-builder';
	private const STYLE_HANDLE = 'cartquill-flow-builder-style';

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	public function add_page(): void {
		\add_submenu_page(
			'',
			\__( 'Flow builder', 'cartquill' ),
			\__( 'Flow builder', 'cartquill' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flow_id = isset( $_GET['flow'] ) ? (int) $_GET['flow'] : 0;
		$this->enqueue( $flow_id );

		echo '<div class="wrap cartquill-admin"><div id="cartquill-flow-builder"></div></div>';
	}

	/**
	 * Enqueue the compiled bundle and hand the app its REST root + nonce + flow id.
	 */
	public function enqueue( int $flow_id ): void {
		$asset = $this->asset();

		\wp_enqueue_script(
			self::HANDLE,
			\plugins_url( 'assets/builder/build/index.js', CARTQUILL_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		\wp_set_script_translations( self::HANDLE, 'cartquill' );

		// The builder's compiled stylesheet (emitted next to the bundle by
		// @wordpress/scripts). wp_style_add_data wires the -rtl variant so the
		// builder is laid out correctly on RTL admin locales.
		\wp_enqueue_style(
			self::STYLE_HANDLE,
			\plugins_url( 'assets/builder/build/style-index.css', CARTQUILL_FILE ),
			array(),
			$asset['version']
		);
		\wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );

		/**
		 * The config handed to the builder app. Add-ons extend it (e.g. the AI add-on
		 * advertises its rewrite feature) via the `features` map without the free core
		 * ever referencing paid code.
		 *
		 * @param array<string, mixed> $config Builder bootstrap config.
		 */
		$config = \apply_filters(
			'cartquill_builder_config',
			array(
				'root'     => \esc_url_raw( \rest_url() ),
				'nonce'    => \wp_create_nonce( 'wp_rest' ),
				'flowId'   => $flow_id,
				'features' => array(),
			)
		);

		\wp_localize_script( self::HANDLE, 'cartquillBuilder', $config );
	}

	/**
	 * The bundle's dependency manifest, or safe defaults when it has not been built
	 * yet (e.g. a source checkout without a compiled bundle).
	 *
	 * @return array{dependencies: list<string>, version: string}
	 */
	private function asset(): array {
		$path = CARTQUILL_PATH . 'assets/builder/build/index.asset.php';
		if ( is_readable( $path ) ) {
			$asset = require $path;
			if ( is_array( $asset ) ) {
				return array(
					'dependencies' => (array) ( $asset['dependencies'] ?? array() ),
					'version'      => (string) ( $asset['version'] ?? CARTQUILL_VERSION ),
				);
			}
		}
		return array(
			'dependencies' => array(),
			'version'      => CARTQUILL_VERSION,
		);
	}
}
