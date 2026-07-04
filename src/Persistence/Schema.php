<?php
/**
 * Custom-table names and DDL.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

/**
 * Owns the plugin's table names and the CREATE statements dbDelta() runs.
 *
 * The spine ships only the `messages` table; the engine slice extends this with
 * flows, enrollments, attributions and settings.
 */
final class Schema {

	/** Bumped whenever the DDL changes so activation can re-run dbDelta. */
	public const VERSION = '1';

	public const OPTION_DB_VERSION = 'flowforge_db_version';

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'flowforge_messages';
	}

	/**
	 * Create/upgrade all tables. Safe to call repeatedly (dbDelta is idempotent).
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$messages        = self::messages_table();

		$sql = "CREATE TABLE {$messages} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			enrollment_id BIGINT UNSIGNED NULL,
			flow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			step_index INT UNSIGNED NOT NULL DEFAULT 0,
			recipient VARCHAR(191) NOT NULL DEFAULT '',
			sender VARCHAR(50) NOT NULL DEFAULT '',
			external_id VARCHAR(191) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY enrollment_id (enrollment_id),
			KEY flow_id (flow_id),
			KEY recipient (recipient)
		) {$charset_collate};";

		\dbDelta( $sql );

		\update_option( self::OPTION_DB_VERSION, self::VERSION );
	}
}
