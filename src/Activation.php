<?php
/**
 * Activation hook: enforce the WooCommerce dependency, then install tables.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge;

use FlowForge\Persistence\Schema;
use FlowForge\Support\Requirements;

final class Activation {

	/**
	 * Runs on plugin activation. Aborts with a clear message if WooCommerce 8+
	 * is not active, so the store owner is never left with a broken setup.
	 */
	public static function activate(): void {
		if ( ! self::woocommerce_ready() ) {
			\deactivate_plugins( \plugin_basename( FLOWFORGE_FILE ) );
			\wp_die(
				\esc_html__(
					'FlowForge requires WooCommerce 8.0 or newer to be installed and active.',
					'flowforge'
				),
				\esc_html__( 'Plugin dependency check', 'flowforge' ),
				array( 'back_link' => true )
			);
		}

		Schema::install();
	}

	/**
	 * Whether WooCommerce 8+ is present right now.
	 */
	public static function woocommerce_ready(): bool {
		$active  = class_exists( 'WooCommerce' );
		$version = null;

		if ( defined( 'WC_VERSION' ) ) {
			$version = (string) WC_VERSION;
		} elseif ( function_exists( 'WC' ) ) {
			$version = \WC()->version ?? null;
		}

		return Requirements::woocommerce_satisfied( $active, $version );
	}
}
