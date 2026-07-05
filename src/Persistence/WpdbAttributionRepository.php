<?php
/**
 * $wpdb-backed AttributionRepository (runtime implementation).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

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
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

	public function revenue_by_flow(): array {
		global $wpdb;
		$table = Schema::attributions_table();

		$rows = $wpdb->get_results( "SELECT flow_id, SUM(revenue) AS revenue FROM {$table} GROUP BY flow_id", ARRAY_A ); // phpcs:ignore WordPress.DB

		$totals = array();
		foreach ( $rows ?: array() as $row ) {
			$totals[ (int) $row['flow_id'] ] = (float) $row['revenue'];
		}
		return $totals;
	}

	public function all(): array {
		global $wpdb;
		$table = Schema::attributions_table();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB

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
