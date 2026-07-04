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
		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function find( int $id ): ?EnrollmentRecord {
		return $this->records[ $id ] ?? null;
	}

	public function all(): array {
		return array_values( $this->records );
	}
}
