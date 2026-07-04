<?php
/**
 * Persistence seam for the `messages` table.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

/**
 * Keeps the engine free of $wpdb so it can be tested against an in-memory
 * implementation. The wpdb-backed implementation is used at runtime.
 */
interface MessageRepository {

	/**
	 * Persist a record and return it with its id populated.
	 */
	public function save( MessageRecord $record ): MessageRecord;

	/**
	 * Atomically reserve the (enrollment, step) slot before sending.
	 *
	 * Inserts the record relying on the unique (enrollment_id, step_index)
	 * index. Returns the stored record (id populated) on success, or null if
	 * the slot is already taken — the pre-send lock that makes double-sends
	 * impossible even under concurrent workers or retries.
	 */
	public function claim( MessageRecord $record ): ?MessageRecord;

	/**
	 * Fetch a record by id, or null if it does not exist.
	 */
	public function find( int $id ): ?MessageRecord;

	/**
	 * Whether a message has already been recorded for this (enrollment, step).
	 *
	 * The engine's idempotency guard: a step that already produced a message is
	 * never sent again. Backed by the unique (enrollment_id, step_index) index.
	 */
	public function exists_for_step( int $enrollment_id, int $step_index ): bool;

	/**
	 * All records (primarily for tests and reporting).
	 *
	 * @return list<MessageRecord>
	 */
	public function all(): array;
}
