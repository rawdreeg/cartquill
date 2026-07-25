<?php
/**
 * $wpdb-backed MessageRepository (runtime implementation).
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
 * Rows are read uncached deliberately: they carry the unique
 * (enrollment, step) key the engine checks to guarantee it never double-sends,
 * so a stale object-cache hit would mail a customer the same message twice.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above.

/**
 * Persists message rows to the custom `messages` table via $wpdb.
 *
 * The engine only depends on the MessageRepository interface, so this class is
 * a thin translation layer and stays out of the tested core.
 */
final class WpdbMessageRepository implements MessageRepository {

	/** Column formats for row_data(), in key order. */
	private const ROW_FORMATS = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );

	/**
	 * The insert/update column map shared by save() and claim().
	 *
	 * @return array<string, mixed>
	 */
	private function row_data( MessageRecord $record ): array {
		return array(
			'enrollment_id' => $record->enrollment_id,
			'flow_id'       => $record->flow_id,
			'step_index'    => $record->step_index,
			'recipient'     => strtolower( trim( $record->recipient ) ),
			'sender'        => $record->sender,
			'external_id'   => $record->external_id,
			'status'        => $record->status,
			'attempts'      => $record->attempts,
			'sent_at'       => $record->sent_at,
			'channel'       => $record->channel,
			'target'        => $record->target,
		);
	}

	/**
	 * SQL fragment listing the customer-facing channels counted for attribution
	 * and engagement stats. Built from a code constant (no user input), so it is
	 * safe to interpolate.
	 */
	private static function customer_channels_in(): string {
		return "'" . implode( "','", array_map( '\esc_sql', MessageRecord::CUSTOMER_CHANNELS ) ) . "'";
	}

	public function save( MessageRecord $record ): MessageRecord {
		global $wpdb;
		$table = Schema::messages_table();

		$data    = $this->row_data( $record );
		$formats = self::ROW_FORMATS;

		if ( null !== $record->id ) {
			$wpdb->update( $table, $data, array( 'id' => $record->id ), $formats, array( '%d' ) );
			return $record;
		}

		$wpdb->insert( $table, $data, $formats );
		return $record->with_id( (int) $wpdb->insert_id );
	}

	public function claim( MessageRecord $record ): ?MessageRecord {
		global $wpdb;
		$table = Schema::messages_table();

		$data    = $this->row_data( $record );
		$formats = self::ROW_FORMATS;

		// Rely on the unique (enrollment_id, step_index) index as a lock: a
		// concurrent worker's insert fails here, so only one send ever happens.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( $table, $data, $formats );
		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			return null; // Duplicate key (or error) — slot already claimed.
		}

		return $record->with_id( (int) $wpdb->insert_id );
	}

	public function find( int $id ): ?MessageRecord {
		global $wpdb;
		$table = Schema::messages_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function find_by_external_id( string $external_id ): ?MessageRecord {
		if ( '' === $external_id ) {
			return null;
		}
		global $wpdb;
		$table = Schema::messages_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE external_id = %s ORDER BY id DESC LIMIT 1", $external_id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function update_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			Schema::messages_table(),
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function exists_for_step( int $enrollment_id, int $step_index ): bool {
		global $wpdb;
		$table = Schema::messages_table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE enrollment_id = %d AND step_index = %d",
				$enrollment_id,
				$step_index
			)
		);

		return (int) $count > 0;
	}

	public function find_for_step( int $enrollment_id, int $step_index ): ?MessageRecord {
		global $wpdb;
		$table = Schema::messages_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE enrollment_id = %d AND step_index = %d LIMIT 1",
				$enrollment_id,
				$step_index
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function latest_to_recipient_between( string $email, string $from, string $to ): ?MessageRecord {
		global $wpdb;
		$table = Schema::messages_table();

		$channels = self::customer_channels_in();
		$row      = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE recipient = %s AND status NOT IN ('queued','failed','bounced','complained')
				AND channel IN ({$channels})
				AND sent_at BETWEEN %s AND %s
				ORDER BY sent_at DESC, id DESC LIMIT 1",
				strtolower( trim( $email ) ),
				$from,
				$to
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function stats_by_flow(): array {
		global $wpdb;
		$table = Schema::messages_table();

		$channels = self::customer_channels_in();
		$rows     = $wpdb->get_results(
			"SELECT flow_id,
				SUM(CASE WHEN status NOT IN ('queued','failed') THEN 1 ELSE 0 END) AS sent,
				SUM(CASE WHEN status IN ('opened','clicked') THEN 1 ELSE 0 END) AS opened,
				SUM(CASE WHEN status = 'clicked' THEN 1 ELSE 0 END) AS clicked,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
			FROM {$table} WHERE channel IN ({$channels}) GROUP BY flow_id",
			ARRAY_A
		);

		$stats = array();
		foreach ( $rows ?: array() as $row ) {
			$stats[ (int) $row['flow_id'] ] = array(
				'sent'    => (int) $row['sent'],
				'opened'  => (int) $row['opened'],
				'clicked' => (int) $row['clicked'],
				'failed'  => (int) $row['failed'],
			);
		}
		return $stats;
	}

	public function delivery_stats_by_flow(): array {
		global $wpdb;
		$table = Schema::messages_table();

		$rows = $wpdb->get_results(
			"SELECT flow_id,
				SUM(CASE WHEN status IN ('delivered','opened','clicked') THEN 1 ELSE 0 END) AS delivered,
				SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
				SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) AS complained
			FROM {$table} GROUP BY flow_id",
			ARRAY_A
		);

		$stats = array();
		foreach ( $rows ?: array() as $row ) {
			$stats[ (int) $row['flow_id'] ] = array(
				'delivered'  => (int) $row['delivered'],
				'bounced'    => (int) $row['bounced'],
				'complained' => (int) $row['complained'],
			);
		}
		return $stats;
	}

	public function for_recipient( string $email ): array {
		global $wpdb;
		$table = Schema::messages_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE recipient = %s ORDER BY id ASC", strtolower( trim( $email ) ) ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	public function delete_for_recipient( string $email ): int {
		global $wpdb;
		return (int) $wpdb->delete(
			Schema::messages_table(),
			array( 'recipient' => strtolower( trim( $email ) ) ),
			array( '%s' )
		);
	}

	public function all(): array {
		global $wpdb;
		$table = Schema::messages_table();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): MessageRecord {
		return new MessageRecord(
			id: (int) $row['id'],
			enrollment_id: null !== $row['enrollment_id'] ? (int) $row['enrollment_id'] : null,
			flow_id: (int) $row['flow_id'],
			step_index: (int) $row['step_index'],
			recipient: (string) $row['recipient'],
			sender: (string) $row['sender'],
			status: (string) $row['status'],
			external_id: null !== $row['external_id'] ? (string) $row['external_id'] : null,
			sent_at: null !== $row['sent_at'] ? (string) $row['sent_at'] : null,
			attempts: isset( $row['attempts'] ) ? (int) $row['attempts'] : 0,
			channel: isset( $row['channel'] ) && '' !== (string) $row['channel'] ? (string) $row['channel'] : MessageRecord::CHANNEL_EMAIL,
			target: isset( $row['target'] ) && null !== $row['target'] ? (string) $row['target'] : null,
		);
	}
}
