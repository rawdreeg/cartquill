<?php
/**
 * Freemius SDK bootstrap (secret-free scaffold).
 *
 * Exposes cartquill_fs(): the shared Freemius SDK instance in production, or null
 * when the SDK/identifiers are absent. This is a STRICT no-op unless BOTH the SDK
 * is vendored (<plugin>/freemius/start.php present) AND the product identifiers are
 * defined via the CARTQUILL_FS_ID and CARTQUILL_FS_PUBLIC_KEY constants. Until the
 * owner drops those in, cartquill_fs() returns null and the plugin runs exactly as
 * it does today, so the existing test suite and any live install without the SDK
 * are completely unaffected.
 *
 * No secret_key ever lives in plugin code — Freemius manages it server-side.
 *
 * @package CartQuill
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

if ( ! function_exists( 'cartquill_fs' ) ) {
	/**
	 * The shared Freemius SDK instance, or null when the SDK/identifiers are absent.
	 *
	 * @return \Freemius|null
	 */
	function cartquill_fs() {
		global $cartquill_fs;

		if ( isset( $cartquill_fs ) ) {
			return $cartquill_fs;
		}

		// TODO(owner): vendor the Freemius SDK into <plugin>/freemius/ (the official
		// WordPress SDK download). Do NOT commit a secret_key. Until this file exists
		// the bootstrap stays a no-op.
		$sdk_start = ( defined( 'CARTQUILL_PATH' ) ? CARTQUILL_PATH : \plugin_dir_path( __DIR__ ) ) . 'freemius/start.php';

		// TODO(owner): paste the Product ID + Public Key from the Freemius dashboard, e.g.
		//   define( 'CARTQUILL_FS_ID', 1234 );
		//   define( 'CARTQUILL_FS_PUBLIC_KEY', 'pk_xxxxxxxxxxxxxxxxxxxxxxxxx' );
		// (define them in wp-config.php or the main plugin file; NEVER a secret_key here).
		if ( ! is_readable( $sdk_start ) || ! defined( 'CARTQUILL_FS_ID' ) || ! defined( 'CARTQUILL_FS_PUBLIC_KEY' ) ) {
			$cartquill_fs = null;
			return $cartquill_fs;
		}

		require_once $sdk_start;

		$cartquill_fs = fs_dynamic_init(
			array(
				'id'               => CARTQUILL_FS_ID,
				'slug'             => 'cartquill',
				'premium_slug'     => 'cartquill-premium',
				'type'             => 'plugin',
				'public_key'       => CARTQUILL_FS_PUBLIC_KEY,
				'is_premium'       => defined( 'CARTQUILL_FS_IS_PREMIUM' ) ? (bool) CARTQUILL_FS_IS_PREMIUM : false,
				'is_premium_only'  => false,
				'has_addons'       => false,
				'has_paid_plans'   => true,
				'is_org_compliant' => true,
				'menu'             => array(
					'slug'    => 'cartquill',
					'account' => true,
					'contact' => false,
					'support' => false,
				),
			)
		);

		/**
		 * Signal that the Freemius SDK is loaded and initialized.
		 */
		\do_action( 'cartquill_fs_loaded' );

		return $cartquill_fs;
	}
}
