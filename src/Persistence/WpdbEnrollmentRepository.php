<?php
/**
 * $wpdb-backed EnrollmentRepository (runtime implementation).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class WpdbEnrollmentRepository implements EnrollmentRepository {

	/** Column formats for row_data(), in key order. */
	private const ROW_FORMATS = array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

	public function save( EnrollmentRecord $record ): EnrollmentRecord {
		global $wpdb;
		$table = Schema::enrollments_table();

		$data = $this->row_data( $record );

		if ( null !== $record->id ) {
			$wpdb->update( $table, $data, array( 'id' => $record->id ), self::ROW_FORMATS, array( '%d' ) );
			return $record;
		}

		$wpdb->insert( $table, $data, self::ROW_FORMATS );
		return $record->with_id( (int) $wpdb->insert_id );
	}

	public function create( EnrollmentRecord $record ): ?EnrollmentRecord {
		global $wpdb;
		$table = Schema::enrollments_table();

		// Rely on the unique active_key index as a lock: a concurrent insert for
		// an already-active (flow, customer) fails here, so only one active
		// enrollment can exist even if two triggers fire at once.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( $table, $this->row_data( $record ), self::ROW_FORMATS );
		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			return null; // Already actively enrolled.
		}

		return $record->with_id( (int) $wpdb->insert_id );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row_data( EnrollmentRecord $record ): array {
		return array(
			'flow_id'        => $record->flow_id,
			'customer_email' => $record->customer_email,
			'status'         => $record->status,
			'current_step'   => $record->current_step,
			'next_run_at'    => $record->next_run_at,
			'created_at'     => $record->created_at ?? \current_time( 'mysql', true ),
			'source'         => $record->source,
			'active_key'     => $this->active_key( $record ),
		);
	}

	/**
	 * The unique key for an active enrollment (else null, so many non-active
	 * rows for the same customer/flow coexist).
	 */
	private function active_key( EnrollmentRecord $record ): ?string {
		if ( EnrollmentRecord::STATUS_ACTIVE !== $record->status ) {
			return null;
		}
		return md5( $record->flow_id . ':' . strtolower( trim( $record->customer_email ) ) );
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

	public function for_customer( string $customer_email ): array {
		global $wpdb;
		$table = Schema::enrollments_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE customer_email = %s ORDER BY id ASC", strtolower( trim( $customer_email ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	public function unsubscribe_customer( string $customer_email ): int {
		global $wpdb;
		$table = Schema::enrollments_table();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, active_key = NULL WHERE customer_email = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				EnrollmentRecord::STATUS_UNSUBSCRIBED,
				strtolower( trim( $customer_email ) ),
				EnrollmentRecord::STATUS_ACTIVE
			)
		);
	}

	public function delete_for_customer( string $customer_email ): int {
		global $wpdb;
		return (int) $wpdb->delete(
			Schema::enrollments_table(),
			array( 'customer_email' => strtolower( trim( $customer_email ) ) ),
			array( '%s' )
		);
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
