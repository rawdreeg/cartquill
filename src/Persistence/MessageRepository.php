<?php
/**
 * Persistence seam for the `messages` table.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

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
	 * Fetch a record by its transport external id (e.g. a Resend message id),
	 * or null. The lookup ESP webhooks use to correlate a delivery event back to
	 * the message that produced it.
	 */
	public function find_by_external_id( string $external_id ): ?MessageRecord;

	/**
	 * Update just the lifecycle status of a message (e.g. sent -> opened).
	 */
	public function update_status( int $id, string $status ): void;

	/**
	 * Whether a message has already been recorded for this (enrollment, step).
	 *
	 * The engine's idempotency guard: a step that already produced a message is
	 * never sent again. Backed by the unique (enrollment_id, step_index) index.
	 */
	public function exists_for_step( int $enrollment_id, int $step_index ): bool;

	/**
	 * The most recent successfully-sent message to $email whose sent_at falls in
	 * [$from, $to] (MySQL datetimes). This is the last-touch lookup used for
	 * revenue attribution; null if the customer had no message in the window.
	 */
	public function latest_to_recipient_between( string $email, string $from, string $to ): ?MessageRecord;

	/**
	 * Per-flow engagement counts for reporting.
	 *
	 * `sent` counts every message that was sent (any status past queued/failed);
	 * `opened` counts opened-or-clicked (a click implies an open); `clicked`
	 * counts clicks.
	 *
	 * @return array<int, array{sent: int, opened: int, clicked: int}>
	 */
	public function stats_by_flow(): array;

	/**
	 * Per-flow delivery counts from ESP webhooks (Deliverability add-on).
	 *
	 * @return array<int, array{delivered: int, bounced: int, complained: int}>
	 */
	public function delivery_stats_by_flow(): array;

	/**
	 * All messages to a recipient (GDPR export).
	 *
	 * @return list<MessageRecord>
	 */
	public function for_recipient( string $email ): array;

	/**
	 * Delete all messages to a recipient (GDPR erase).
	 *
	 * @return int Number deleted.
	 */
	public function delete_for_recipient( string $email ): int;

	/**
	 * All records (primarily for tests and reporting).
	 *
	 * @return list<MessageRecord>
	 */
	public function all(): array;
}
