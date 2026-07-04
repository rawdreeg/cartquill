<?php
/**
 * In-memory FlowRepository for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

final class InMemoryFlowRepository implements FlowRepository {

	/** @var array<int, FlowRecord> */
	private array $records = array();

	private int $next_id = 1;

	public function save( FlowRecord $record ): FlowRecord {
		if ( null !== $record->id ) {
			$this->records[ $record->id ] = $record;
			return $record;
		}

		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function find( int $id ): ?FlowRecord {
		return $this->records[ $id ] ?? null;
	}

	public function active_by_type( string $type ): array {
		return array_values(
			array_filter(
				$this->records,
				static fn( FlowRecord $r ) => $r->type === $type && $r->is_active()
			)
		);
	}

	public function all(): array {
		return array_values( $this->records );
	}
}
