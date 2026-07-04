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
	 * @return list<EnrollmentRecord>
	 */
	public function all(): array;
}
