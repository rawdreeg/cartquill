<?php
/**
 * $wpdb-backed SuppressionList, stored in the `settings` table.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Compliance;

use FlowForge\Persistence\Schema;

/**
 * Persists suppressed addresses as unique rows in the settings table
 * (`setting_key = "suppress:<email>"`), giving O(1) indexed lookups. The
 * compliance and deliverability slices write to this via suppress().
 */
final class WpdbSuppressionList implements SuppressionList {

	public function is_suppressed( string $email ): bool {
		global $wpdb;
		$table = Schema::settings_table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE setting_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$this->key( $email )
			)
		);

		return (int) $count > 0;
	}

	public function suppress( string $email, string $reason = '' ): void {
		global $wpdb;
		$table = Schema::settings_table();

		// INSERT ... ON DUPLICATE KEY UPDATE keeps this idempotent.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (setting_key, setting_value) VALUES (%s, %s) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", // phpcs:ignore WordPress.DB.PreparedSQL
				$this->key( $email ),
				$reason
			)
		);
	}

	public function remove( string $email ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::settings_table(),
			array( 'setting_key' => $this->key( $email ) ),
			array( '%s' )
		);
	}

	private function key( string $email ): string {
		return 'suppress:' . strtolower( trim( $email ) );
	}
}
