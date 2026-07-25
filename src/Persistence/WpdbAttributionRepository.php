<?php
/**
 * $wpdb-backed AttributionRepository (runtime implementation).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/*
 * Direct $wpdb access is this class's entire job: CartQuill owns its custom
 * tables (see Persistence\Schema) and WordPress ships no API for them. Table
 * names come from Schema::*_table() - `$wpdb->prefix` plus a hard-coded literal,
 * never user input - and an identifier cannot travel through a prepare()
 * placeholder, so the name is interpolated while every *value* stays prepared.
 * Rows are read uncached deliberately: the unique `order_id` insert is
 * what makes attribution exactly-once per order, and the revenue totals have to
 * reflect orders placed since the report was last opened.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above.

final class WpdbAttributionRepository implements AttributionRepository {

	public function record( AttributionRecord $record ): ?AttributionRecord {
		global $wpdb;
		$table = Schema::attributions_table();

		// Unique (order_id) makes this a last-touch, exactly-once-per-order claim.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			$table,
			array(
				'order_id'      => $record->order_id,
				'flow_id'       => $record->flow_id,
				'message_id'    => $record->message_id,
				'revenue'       => $record->revenue,
				'attributed_at' => $record->attributed_at ?? \current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%f', '%s' )
		);
		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			return null; // Already attributed.
		}

		return $record->with_id( (int) $wpdb->insert_id );
	}

	public function find_by_order( int $order_id ): ?AttributionRecord {
		global $wpdb;
		$table = Schema::attributions_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id ),
			ARRAY_A
		);
		if ( null === $row ) {
			return null;
		}

		return new AttributionRecord(
			id: (int) $row['id'],
			order_id: (int) $row['order_id'],
			flow_id: (int) $row['flow_id'],
			message_id: null !== $row['message_id'] ? (int) $row['message_id'] : null,
			revenue: (float) $row['revenue'],
			attributed_at: null !== $row['attributed_at'] ? (string) $row['attributed_at'] : null,
		);
	}

	public function for_messages( array $message_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $message_ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		global $wpdb;
		$table        = Schema::attributions_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE message_id IN ({$placeholders})", $ids ),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ) => new AttributionRecord(
				id: (int) $row['id'],
				order_id: (int) $row['order_id'],
				flow_id: (int) $row['flow_id'],
				message_id: null !== $row['message_id'] ? (int) $row['message_id'] : null,
				revenue: (float) $row['revenue'],
				attributed_at: null !== $row['attributed_at'] ? (string) $row['attributed_at'] : null,
			),
			$rows ?: array()
		);
	}

	public function anonymize_messages( array $message_ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $message_ids ) ) );
		if ( array() === $ids ) {
			return 0;
		}
		global $wpdb;
		$table        = Schema::attributions_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET message_id = NULL WHERE message_id IN ({$placeholders})", $ids )
		);
	}

	public function revenue_by_flow(): array {
		global $wpdb;
		$table = Schema::attributions_table();

		$rows = $wpdb->get_results( "SELECT flow_id, SUM(revenue) AS revenue FROM {$table} GROUP BY flow_id", ARRAY_A );

		$totals = array();
		foreach ( $rows ?: array() as $row ) {
			$totals[ (int) $row['flow_id'] ] = (float) $row['revenue'];
		}
		return $totals;
	}

	public function all(): array {
		global $wpdb;
		$table = Schema::attributions_table();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		return array_map(
			static fn( array $row ) => new AttributionRecord(
				id: (int) $row['id'],
				order_id: (int) $row['order_id'],
				flow_id: (int) $row['flow_id'],
				message_id: null !== $row['message_id'] ? (int) $row['message_id'] : null,
				revenue: (float) $row['revenue'],
				attributed_at: null !== $row['attributed_at'] ? (string) $row['attributed_at'] : null,
			),
			$rows ?: array()
		);
	}
}
