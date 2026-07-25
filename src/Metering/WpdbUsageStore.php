<?php
/**
 * $wpdb-backed UsageStore (runtime implementation).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Metering;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/*
 * Direct $wpdb access is this class's entire job: CartQuill owns its custom
 * tables (see Persistence\Schema) and WordPress ships no API for them. Table
 * names come from Schema::*_table() - `$wpdb->prefix` plus a hard-coded literal,
 * never user input - and an identifier cannot travel through a prepare()
 * placeholder, so the name is interpolated while every *value* stays prepared.
 * Rows are read uncached deliberately: metering is fail-closed and the
 * counter is incremented atomically by INSERT ... ON DUPLICATE KEY UPDATE, so a
 * cached count would let a store run past its plan's action cap.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above.

use CartQuill\Persistence\Schema;

/**
 * Counts per month in the `usage` table. The increment is a single
 * INSERT ... ON DUPLICATE KEY UPDATE on the unique period key — O(1), and it
 * never scans the messages table to derive usage.
 */
final class WpdbUsageStore implements UsageStore {

	public function count( string $period ): int {
		global $wpdb;
		$table = Schema::usage_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT action_count FROM {$table} WHERE period = %s", $period )
		);
	}

	public function increment( string $period ): void {
		global $wpdb;
		$table = Schema::usage_table();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (period, action_count, updated_at) VALUES (%s, 1, %s)
				ON DUPLICATE KEY UPDATE action_count = action_count + 1, updated_at = VALUES(updated_at)",
				$period,
				\current_time( 'mysql', true )
			)
		);
	}
}
