<?php
/**
 * Activation hook: enforce the WooCommerce dependency, then install tables.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Admin\Onboarding;
use CartQuill\Integration\AbandonedCartScanner;
use CartQuill\Integration\WinBackScanner;
use CartQuill\Persistence\Schema;
use CartQuill\Support\Requirements;

final class Activation {

	/**
	 * Runs on plugin activation. On a network-wide multisite activation every
	 * existing site is provisioned; otherwise it aborts with a clear message if
	 * WooCommerce 8+ is not active, so the store owner is never left with a
	 * broken setup.
	 *
	 * @param bool $network_wide Passed by WordPress when network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( \is_multisite() && $network_wide ) {
			foreach ( \get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				\switch_to_blog( (int) $site_id );
				Schema::install();
				\restore_current_blog();
			}
			return;
		}

		if ( ! self::woocommerce_ready() ) {
			\deactivate_plugins( \plugin_basename( CARTQUILL_FILE ) );
			\wp_die(
				\esc_html__(
					'CartQuill requires WooCommerce 8.0 or newer to be installed and active.',
					'cartquill'
				),
				\esc_html__( 'Plugin dependency check', 'cartquill' ),
				array( 'back_link' => true )
			);
		}

		Schema::install();

		( new Onboarding() )->flag_for_redirect();
	}

	/**
	 * Provision a newly-created site while CartQuill is network-active, so a
	 * multisite network never leaves a later site without its tables.
	 *
	 * @param \WP_Site $site The new site.
	 */
	public static function on_new_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! \is_plugin_active_for_network( \plugin_basename( CARTQUILL_FILE ) ) ) {
			return;
		}

		\switch_to_blog( (int) $site->blog_id );
		Schema::install();
		\restore_current_blog();
	}

	/**
	 * Stop the recurring background scans on deactivation so they don't keep
	 * firing as no-ops while the plugin is inactive.
	 */
	public static function deactivate(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( array( AbandonedCartScanner::HOOK, WinBackScanner::HOOK ) as $hook ) {
			\as_unschedule_all_actions( $hook, array(), 'cartquill' );
		}
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
