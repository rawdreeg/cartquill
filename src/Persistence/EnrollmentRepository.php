<?php
/**
 * Persistence seam for the `flow_enrollments` table.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

interface EnrollmentRepository {

	/**
	 * Insert a new enrollment (id null) or update an existing one; returns the
	 * record with its id populated.
	 */
	public function save( EnrollmentRecord $record ): EnrollmentRecord;

	public function find( int $id ): ?EnrollmentRecord;

	/**
	 * The active enrollment for this customer in this flow, if one exists.
	 *
	 * Used to keep enrollment idempotent — a customer is never enrolled twice
	 * in the same flow run.
	 */
	public function find_active( int $flow_id, string $customer_email ): ?EnrollmentRecord;

	/**
	 * Whether this customer has ever been enrolled in this flow (any status).
	 *
	 * Lets the win-back scan avoid re-engaging someone who already ran the flow.
	 */
	public function has_any( int $flow_id, string $customer_email ): bool;

	/**
	 * All enrollments for a customer (any flow, any status).
	 *
	 * @return list<EnrollmentRecord>
	 */
	public function for_customer( string $customer_email ): array;

	/**
	 * Mark every active enrollment for this customer as unsubscribed.
	 *
	 * @return int Number of enrollments updated.
	 */
	public function unsubscribe_customer( string $customer_email ): int;

	/**
	 * Delete all enrollments for a customer (GDPR erase).
	 *
	 * @return int Number of enrollments deleted.
	 */
	public function delete_for_customer( string $customer_email ): int;

	/**
	 * @return list<EnrollmentRecord>
	 */
	public function all(): array;
}
