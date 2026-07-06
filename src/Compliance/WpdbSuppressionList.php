<?php
/**
 * $wpdb-backed SuppressionList, stored in the `settings` table.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Persistence\Schema;

/**
 * Persists suppressed addresses as unique rows in the settings table
 * (`setting_key = "suppress:<email>"`), giving O(1) indexed lookups. The
 * compliance and deliverability slices write to this via suppress().
 */
final class WpdbSuppressionList implements SuppressionList {

	public function is_suppressed( string $identifier, string $channel = self::CHANNEL_EMAIL ): bool {
		global $wpdb;
		$table = Schema::settings_table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE setting_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$this->key( $identifier, $channel )
			)
		);

		return (int) $count > 0;
	}

	public function suppress( string $identifier, string $reason = '', string $channel = self::CHANNEL_EMAIL ): void {
		global $wpdb;
		$table = Schema::settings_table();

		// INSERT ... ON DUPLICATE KEY UPDATE keeps this idempotent.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (setting_key, setting_value) VALUES (%s, %s) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", // phpcs:ignore WordPress.DB.PreparedSQL
				$this->key( $identifier, $channel ),
				$reason
			)
		);
	}

	public function remove( string $identifier, string $channel = self::CHANNEL_EMAIL ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::settings_table(),
			array( 'setting_key' => $this->key( $identifier, $channel ) ),
			array( '%s' )
		);
	}

	/**
	 * The settings-table key for a suppressed identifier. The email channel keeps
	 * the original `suppress:<email>` shape so existing rows stay valid; other
	 * channels are namespaced as `suppress:<channel>:<identifier>`.
	 */
	private function key( string $identifier, string $channel ): string {
		$id = strtolower( trim( $identifier ) );
		return self::CHANNEL_EMAIL === $channel ? 'suppress:' . $id : 'suppress:' . $channel . ':' . $id;
	}
}
