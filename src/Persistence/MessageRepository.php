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
	 * Fetch a record by id, or null if it does not exist.
	 */
	public function find( int $id ): ?MessageRecord;

	/**
	 * All records (primarily for tests and reporting).
	 *
	 * @return list<MessageRecord>
	 */
	public function all(): array;
}
