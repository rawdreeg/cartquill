<?php
/**
 * $wpdb-backed FlowRepository (runtime implementation).
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
 * Rows are read uncached deliberately: the trigger path reads flow
 * status on every matching WooCommerce event, so a stale read would keep running
 * a flow the store owner just paused.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above.

final class WpdbFlowRepository implements FlowRepository {

	public function save( FlowRecord $record ): FlowRecord {
		global $wpdb;
		$table = Schema::flows_table();

		$data = array(
			'name'       => $record->name,
			'type'       => $record->type,
			'status'     => $record->status,
			'source'     => $record->source,
			'steps'      => $record->steps_json(),
			'created_at' => $record->created_at ?? \current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( null !== $record->id ) {
			$wpdb->update( $table, $data, array( 'id' => $record->id ), $formats, array( '%d' ) );
			return $record;
		}

		$wpdb->insert( $table, $data, $formats );
		return $record->with_id( (int) $wpdb->insert_id );
	}

	public function find( int $id ): ?FlowRecord {
		global $wpdb;
		$table = Schema::flows_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function active_by_type( string $type ): array {
		global $wpdb;
		$table = Schema::flows_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE type = %s AND status = %s ORDER BY id ASC",
				$type,
				FlowRecord::STATUS_ACTIVE
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	public function all(): array {
		global $wpdb;
		$table = Schema::flows_table();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): FlowRecord {
		return new FlowRecord(
			id: (int) $row['id'],
			name: (string) $row['name'],
			type: (string) $row['type'],
			status: (string) $row['status'],
			source: (string) $row['source'],
			steps: FlowRecord::decode_steps( isset( $row['steps'] ) ? (string) $row['steps'] : null ),
			created_at: isset( $row['created_at'] ) && null !== $row['created_at'] ? (string) $row['created_at'] : null,
		);
	}
}
