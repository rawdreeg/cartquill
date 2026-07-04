<?php
/**
 * In-memory EnrollmentRepository for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

final class InMemoryEnrollmentRepository implements EnrollmentRepository {

	/** @var array<int, EnrollmentRecord> */
	private array $records = array();

	private int $next_id = 1;

	public function save( EnrollmentRecord $record ): EnrollmentRecord {
		if ( null !== $record->id ) {
			$this->records[ $record->id ] = $record;
			return $record;
		}

		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function find( int $id ): ?EnrollmentRecord {
		return $this->records[ $id ] ?? null;
	}

	public function find_active( int $flow_id, string $customer_email ): ?EnrollmentRecord {
		foreach ( $this->records as $record ) {
			if ( $record->flow_id === $flow_id
				&& $record->customer_email === $customer_email
				&& $record->is_active()
			) {
				return $record;
			}
		}
		return null;
	}

	public function all(): array {
		return array_values( $this->records );
	}
}
