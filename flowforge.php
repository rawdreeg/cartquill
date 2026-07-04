<?php
/**
 * Plugin Name:       FlowForge
 * Plugin URI:        https://github.com/rawdreeg/flowforge
 * Description:       Standalone WooCommerce email automation: install proven flows, generate them with AI, and report revenue per flow. Free core sends via wp_mail.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Rodrigue Tusse
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flowforge
 * WC requires at least: 8.0
 *
 * @package FlowForge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'FLOWFORGE_FILE', __FILE__ );
define( 'FLOWFORGE_VERSION', '0.1.0' );
define( 'FLOWFORGE_PATH', plugin_dir_path( __FILE__ ) );

$flowforge_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $flowforge_autoload ) ) {
	require_once $flowforge_autoload;
}

register_activation_hook( __FILE__, array( \FlowForge\Activation::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		// Apply pending schema migrations before the engine touches the tables:
		// WordPress does not fire the activation hook on in-place updates.
		( new \FlowForge\Persistence\Migrator() )->maybe_upgrade();
		\FlowForge\Plugin::boot();
	}
);
