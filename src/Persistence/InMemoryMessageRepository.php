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
 * auto-increment column would. Mirrors the DB's unique (enrollment_id,
 * step_index) index by rejecting a duplicate insert, so idempotency can be
 * asserted even when a test bypasses the engine's own guard.
 */
final class InMemoryMessageRepository implements MessageRepository {

	/** @var array<int, MessageRecord> */
	private array $records = array();

	/** @var array<string, true> Seen (enrollment_id:step_index) keys. */
	private array $unique = array();

	private int $next_id = 1;

	public function save( MessageRecord $record ): MessageRecord {
		$key = $this->unique_key( $record->enrollment_id, $record->step_index );
		if ( null !== $key ) {
			if ( isset( $this->unique[ $key ] ) && null === $record->id ) {
				throw new \RuntimeException( "Duplicate message for {$key} (unique constraint)." );
			}
			$this->unique[ $key ] = true;
		}

		if ( null !== $record->id ) {
			$this->records[ $record->id ] = $record;
			return $record;
		}

		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function claim( MessageRecord $record ): ?MessageRecord {
		$key = $this->unique_key( $record->enrollment_id, $record->step_index );
		if ( null !== $key && isset( $this->unique[ $key ] ) ) {
			return null; // Slot already taken.
		}
		return $this->save( $record );
	}

	public function find( int $id ): ?MessageRecord {
		return $this->records[ $id ] ?? null;
	}

	public function update_status( int $id, string $status ): void {
		if ( isset( $this->records[ $id ] ) ) {
			$this->records[ $id ] = $this->records[ $id ]->with_result(
				$status,
				$this->records[ $id ]->external_id,
				$this->records[ $id ]->sent_at,
			);
		}
	}

	public function exists_for_step( int $enrollment_id, int $step_index ): bool {
		return isset( $this->unique[ $enrollment_id . ':' . $step_index ] );
	}

	public function all(): array {
		return array_values( $this->records );
	}

	private function unique_key( ?int $enrollment_id, int $step_index ): ?string {
		return null !== $enrollment_id ? $enrollment_id . ':' . $step_index : null;
	}
}
