<?php
/**
 * Uninstall cleanup.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * What running "Delete" on CartQuill removes.
 *
 * The recurring background scans are always unscheduled; the plugin's data
 * (custom tables and stored options/transients) is preserved unless "Delete all
 * CartQuill data" was enabled in CartQuill Settings before deletion.
 *
 * This lives in a class rather than in `uninstall.php` because WordPress offers
 * two uninstall mechanisms and they are mutually exclusive: if `uninstall.php`
 * exists, `uninstall_plugin()` includes it and returns, never consulting the
 * `uninstall_plugins` option that `register_uninstall_hook()` writes to. An
 * edition that needs the hook — because something else already registered one —
 * therefore cannot also ship the file, and both routes need to run this same
 * code.
 */
final class Uninstaller {

	/** Tables dropped when the store opted into data removal. */
	private const TABLES = array(
		'cartquill_flows',
		'cartquill_enrollments',
		'cartquill_messages',
		'cartquill_attributions',
		'cartquill_settings',
		'cartquill_cart_captures',
	);

	/**
	 * Clean up, across every site on a network install.
	 */
	public static function run(): void {
		// Always stop the recurring background scans, regardless of the data
		// setting: leaving them scheduled would fire actions for a plugin that is
		// no longer there.
		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( '', array(), 'cartquill' );
		}

		if ( \is_multisite() ) {
			foreach ( \get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				\switch_to_blog( (int) $site_id );
				self::run_for_current_site();
				\restore_current_blog();
			}
			return;
		}

		self::run_for_current_site();
	}

	/**
	 * Remove CartQuill data from the current site, but only if the store opted in.
	 */
	private static function run_for_current_site(): void {
		global $wpdb;

		$settings = \get_option( 'cartquill_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['remove_data_on_uninstall'] ) ) {
			return;
		}

		foreach ( self::TABLES as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}

		$prefix_like = $wpdb->esc_like( 'cartquill_' ) . '%';
		$trans_like  = $wpdb->esc_like( '_transient_cartquill_' ) . '%';
		$ttime_like  = $wpdb->esc_like( '_transient_timeout_cartquill_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$prefix_like,
				$trans_like,
				$ttime_like
			)
		);
	}
}
