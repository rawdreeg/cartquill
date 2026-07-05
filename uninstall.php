<?php
/**
 * Uninstall cleanup.
 *
 * WordPress runs this only when the store owner deletes FlowForge. The recurring
 * background scans are always unscheduled; the plugin's data (custom tables and
 * stored options/transients) is preserved unless "Delete all FlowForge data" was
 * enabled in FlowForge Settings before deletion.
 *
 * @package FlowForge
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove FlowForge data from the current site, but only if the store opted in.
 */
function flowforge_uninstall_current_site(): void {
	global $wpdb;

	$settings = get_option( 'flowforge_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['remove_data_on_uninstall'] ) ) {
		return;
	}

	$tables = array(
		'flowforge_flows',
		'flowforge_enrollments',
		'flowforge_messages',
		'flowforge_attributions',
		'flowforge_settings',
		'flowforge_cart_captures',
	);
	foreach ( $tables as $table ) {
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
	}

	$prefix_like = $wpdb->esc_like( 'flowforge_' ) . '%';
	$trans_like  = $wpdb->esc_like( '_transient_flowforge_' ) . '%';
	$ttime_like  = $wpdb->esc_like( '_transient_timeout_flowforge_' ) . '%';
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

// Always stop the recurring background scans, regardless of the data setting.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'flowforge' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		flowforge_uninstall_current_site();
		restore_current_blog();
	}
} else {
	flowforge_uninstall_current_site();
}
