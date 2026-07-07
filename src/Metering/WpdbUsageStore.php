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
			$wpdb->prepare( "SELECT action_count FROM {$table} WHERE period = %s", $period ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	public function increment( string $period ): void {
		global $wpdb;
		$table = Schema::usage_table();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (period, action_count, updated_at) VALUES (%s, 1, %s)
				ON DUPLICATE KEY UPDATE action_count = action_count + 1, updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL
				$period,
				\current_time( 'mysql', true )
			)
		);
	}
}
