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

/*
 * Direct $wpdb access is this class's entire job: CartQuill owns its custom
 * tables (see Persistence\Schema) and WordPress ships no API for them. Table
 * names come from Schema::*_table() - `$wpdb->prefix` plus a hard-coded literal,
 * never user input - and an identifier cannot travel through a prepare()
 * placeholder, so the name is interpolated while every *value* stays prepared.
 * Rows are read uncached deliberately: suppression is checked before
 * every single send, and a stale hit would email someone who has unsubscribed.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above.

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
				"SELECT COUNT(*) FROM {$table} WHERE setting_key = %s",
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
				"INSERT INTO {$table} (setting_key, setting_value) VALUES (%s, %s) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
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
