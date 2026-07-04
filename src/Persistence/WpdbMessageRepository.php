<?php
/**
 * $wpdb-backed MessageRepository (runtime implementation).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

/**
 * Persists message rows to the custom `messages` table via $wpdb.
 *
 * The engine only depends on the MessageRepository interface, so this class is
 * a thin translation layer and stays out of the tested core.
 */
final class WpdbMessageRepository implements MessageRepository {

	public function save( MessageRecord $record ): MessageRecord {
		global $wpdb;
		$table = Schema::messages_table();

		$data = array(
			'enrollment_id' => $record->enrollment_id,
			'flow_id'       => $record->flow_id,
			'step_index'    => $record->step_index,
			'recipient'     => $record->recipient,
			'sender'        => $record->sender,
			'external_id'   => $record->external_id,
			'status'        => $record->status,
			'sent_at'       => $record->sent_at,
		);
		$formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' );

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

		$data = array(
			'enrollment_id' => $record->enrollment_id,
			'flow_id'       => $record->flow_id,
			'step_index'    => $record->step_index,
			'recipient'     => $record->recipient,
			'sender'        => $record->sender,
			'external_id'   => $record->external_id,
			'status'        => $record->status,
			'sent_at'       => $record->sent_at,
		);
		$formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' );

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
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
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
				"SELECT COUNT(*) FROM {$table} WHERE enrollment_id = %d AND step_index = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$enrollment_id,
				$step_index
			)
		);

		return (int) $count > 0;
	}

	public function latest_to_recipient_between( string $email, string $from, string $to ): ?MessageRecord {
		global $wpdb;
		$table = Schema::messages_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE recipient = %s AND status NOT IN ('queued','failed','bounced','complained')
				AND sent_at BETWEEN %s AND %s
				ORDER BY sent_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
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

		$rows = $wpdb->get_results(
			"SELECT flow_id,
				SUM(CASE WHEN status NOT IN ('queued','failed') THEN 1 ELSE 0 END) AS sent,
				SUM(CASE WHEN status IN ('opened','clicked') THEN 1 ELSE 0 END) AS opened,
				SUM(CASE WHEN status = 'clicked' THEN 1 ELSE 0 END) AS clicked
			FROM {$table} GROUP BY flow_id", // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$stats = array();
		foreach ( $rows ?: array() as $row ) {
			$stats[ (int) $row['flow_id'] ] = array(
				'sent'    => (int) $row['sent'],
				'opened'  => (int) $row['opened'],
				'clicked' => (int) $row['clicked'],
			);
		}
		return $stats;
	}

	public function for_recipient( string $email ): array {
		global $wpdb;
		$table = Schema::messages_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE recipient = %s ORDER BY id ASC", strtolower( trim( $email ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL
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

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB

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
		);
	}
}
