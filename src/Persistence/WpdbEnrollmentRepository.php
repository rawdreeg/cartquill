<?php
/**
 * $wpdb-backed EnrollmentRepository (runtime implementation).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

final class WpdbEnrollmentRepository implements EnrollmentRepository {

	public function save( EnrollmentRecord $record ): EnrollmentRecord {
		global $wpdb;
		$table = Schema::enrollments_table();

		$data = array(
			'flow_id'        => $record->flow_id,
			'customer_email' => $record->customer_email,
			'status'         => $record->status,
			'current_step'   => $record->current_step,
			'next_run_at'    => $record->next_run_at,
			'created_at'     => $record->created_at ?? \current_time( 'mysql', true ),
			'source'         => $record->source,
		);
		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' );

		if ( null !== $record->id ) {
			$wpdb->update( $table, $data, array( 'id' => $record->id ), $formats, array( '%d' ) );
			return $record;
		}

		$wpdb->insert( $table, $data, $formats );
		return $record->with_id( (int) $wpdb->insert_id );
	}

	public function find( int $id ): ?EnrollmentRecord {
		global $wpdb;
		$table = Schema::enrollments_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function find_active( int $flow_id, string $customer_email ): ?EnrollmentRecord {
		global $wpdb;
		$table = Schema::enrollments_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE flow_id = %d AND customer_email = %s AND status = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$flow_id,
				$customer_email,
				EnrollmentRecord::STATUS_ACTIVE
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function has_any( int $flow_id, string $customer_email ): bool {
		global $wpdb;
		$table = Schema::enrollments_table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE flow_id = %d AND customer_email = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$flow_id,
				$customer_email
			)
		);

		return (int) $count > 0;
	}

	public function all(): array {
		global $wpdb;
		$table = Schema::enrollments_table();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): EnrollmentRecord {
		return new EnrollmentRecord(
			id: (int) $row['id'],
			flow_id: (int) $row['flow_id'],
			customer_email: (string) $row['customer_email'],
			status: (string) $row['status'],
			current_step: (int) $row['current_step'],
			next_run_at: isset( $row['next_run_at'] ) && null !== $row['next_run_at'] ? (string) $row['next_run_at'] : null,
			created_at: null !== $row['created_at'] ? (string) $row['created_at'] : null,
			source: isset( $row['source'] ) ? (string) $row['source'] : '',
		);
	}
}
