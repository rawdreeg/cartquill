<?php
/**
 * Plugin Name:       CartQuill
 * Plugin URI:        https://github.com/rawdreeg/cartquill
 * Description:       Standalone WooCommerce email automation: install proven flows, generate them with AI, and report revenue per flow. Free core sends via wp_mail.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Rodrigue Tusse
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cartquill
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 *
 * @package CartQuill
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'CARTQUILL_FILE', __FILE__ );
define( 'CARTQUILL_VERSION', '0.1.0' );
define( 'CARTQUILL_PATH', plugin_dir_path( __FILE__ ) );

$cartquill_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $cartquill_autoload ) ) {
	// A packaged release bundles its autoloader; a raw source checkout does
	// not. Bail with an admin notice instead of fataling in Plugin::boot().
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'CartQuill could not start because its autoloader is missing. Install the packaged release, or run "composer install" in the plugin directory.',
				'cartquill'
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once $cartquill_autoload;

register_activation_hook( __FILE__, array( \CartQuill\Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \CartQuill\Activation::class, 'deactivate' ) );

// Provision tables for sites created later on a network-active install.
add_action( 'wp_initialize_site', array( \CartQuill\Activation::class, 'on_new_site' ), 20 );

// Declare High-Performance Order Storage compatibility (WooCommerce 8+).
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CARTQUILL_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		// Apply pending schema migrations before the engine touches the tables:
		// WordPress does not fire the activation hook on in-place updates.
		( new \CartQuill\Persistence\Migrator() )->maybe_upgrade();
		\CartQuill\Plugin::boot();
	}
);
