<?php
/**
 * Custom-table names and DDL.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Owns the plugin's table names and the CREATE statements dbDelta() runs.
 *
 * The engine slice establishes all five tables from the data model. Later
 * slices populate the ones created empty here (attributions in the reporting
 * slice; the settings table's suppression rows in the compliance and
 * deliverability slices).
 */
final class Schema {

	/** Bumped whenever the DDL changes so {@see Migrator} re-applies dbDelta on update. */
	public const VERSION = '8';

	public const OPTION_DB_VERSION = 'flowforge_db_version';

	public static function flows_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'flowforge_flows';
	}

	public static function enrollments_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'flowforge_enrollments';
	}

	public static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'flowforge_messages';
	}

	public static function attributions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'flowforge_attributions';
	}

	public static function settings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'flowforge_settings';
	}

	/**
	 * Trigger-support table for abandoned-cart tracking (not part of the core
	 * five-table data model; it feeds the abandoned-cart trigger).
	 */
	public static function cart_captures_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'flowforge_cart_captures';
	}

	/**
	 * Create or upgrade all tables via dbDelta (idempotent and additive). Runs
	 * on activation and, via {@see Migrator}, on the first normal request after
	 * the stored DB version falls behind {@see self::VERSION} — WordPress does
	 * not fire the activation hook on in-place updates.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$flows        = self::flows_table();
		$enrollments  = self::enrollments_table();
		$messages     = self::messages_table();
		$attributions = self::attributions_table();
		$settings     = self::settings_table();

		$statements = array();

		$statements[] = "CREATE TABLE {$flows} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			type VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			source VARCHAR(20) NOT NULL DEFAULT 'template',
			steps LONGTEXT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY status (status)
		) {$charset_collate};";

		// active_key is a hash of (flow_id, email) set only while the enrollment
		// is active, else NULL. The unique index (which allows many NULLs) makes
		// at most one *active* enrollment per (flow, customer) — re-enrollment
		// after a prior run went non-active is still allowed.
		$statements[] = "CREATE TABLE {$enrollments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			flow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_email VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			current_step INT UNSIGNED NOT NULL DEFAULT 0,
			next_run_at DATETIME NULL,
			created_at DATETIME NULL,
			source VARCHAR(30) NOT NULL DEFAULT '',
			active_key CHAR(32) NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY active_key (active_key),
			KEY flow_id (flow_id),
			KEY customer_email (customer_email),
			KEY status (status)
		) {$charset_collate};";

		// Unique (enrollment_id, step_index) enforces idempotency: a given step
		// of a given enrollment can be recorded at most once.
		$statements[] = "CREATE TABLE {$messages} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			enrollment_id BIGINT UNSIGNED NULL,
			flow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			step_index INT UNSIGNED NOT NULL DEFAULT 0,
			recipient VARCHAR(191) NOT NULL DEFAULT '',
			sender VARCHAR(50) NOT NULL DEFAULT '',
			external_id VARCHAR(191) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY enrollment_step (enrollment_id, step_index),
			KEY flow_id (flow_id),
			KEY recipient (recipient),
			KEY external_id (external_id)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$attributions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			flow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			message_id BIGINT UNSIGNED NULL,
			revenue DECIMAL(18,4) NOT NULL DEFAULT 0,
			attributed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY flow_id (flow_id)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$settings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			setting_key VARCHAR(191) NOT NULL DEFAULT '',
			setting_value LONGTEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY setting_key (setting_key)
		) {$charset_collate};";

		$captures = self::cart_captures_table();
		$statements[] = "CREATE TABLE {$captures} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_email VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_email (customer_email),
			KEY status (status)
		) {$charset_collate};";

		foreach ( $statements as $sql ) {
			\dbDelta( $sql );
		}

		\update_option( self::OPTION_DB_VERSION, self::VERSION );
	}
}
