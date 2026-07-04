<?php
/**
 * In-memory MessageRepository for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

/**
 * Stores records in a plain array, assigning sequential ids like an
 * auto-increment column would.
 */
final class InMemoryMessageRepository implements MessageRepository {

	/** @var array<int, MessageRecord> */
	private array $records = array();

	private int $next_id = 1;

	public function save( MessageRecord $record ): MessageRecord {
		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function find( int $id ): ?MessageRecord {
		return $this->records[ $id ] ?? null;
	}

	public function all(): array {
		return array_values( $this->records );
	}
}
