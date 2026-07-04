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
	 * Persist an enrollment and return it with its id populated.
	 */
	public function save( EnrollmentRecord $record ): EnrollmentRecord;

	public function find( int $id ): ?EnrollmentRecord;

	/**
	 * @return list<EnrollmentRecord>
	 */
	public function all(): array;
}
