<?php
/**
 * Enrolls a customer into a flow and schedules its first step.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Persistence\EnrollmentRecord;
use FlowForge\Persistence\EnrollmentRepository;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Scheduling\Scheduler;
use FlowForge\Support\Clock;

/**
 * The entry point every trigger calls. Creates an active enrollment at step 0
 * and schedules that step at now + its delay. Enrollment is idempotent: a
 * customer already running this flow is not enrolled again.
 */
final class Enroller {

	public function __construct(
		private readonly EnrollmentRepository $enrollments,
		private readonly Scheduler $scheduler,
		private readonly Clock $clock,
	) {}

	/**
	 * @param string $source Consent/trigger source recorded on the enrollment.
	 *
	 * @return EnrollmentRecord|null The new enrollment, or null if the flow is
	 *                               not enrollable or the customer is already
	 *                               enrolled.
	 */
	public function enroll( FlowRecord $flow, string $email, string $source = '' ): ?EnrollmentRecord {
		if ( null === $flow->id || ! $flow->is_active() || array() === $flow->steps ) {
			return null;
		}

		if ( null !== $this->enrollments->find_active( $flow->id, $email ) ) {
			return null;
		}

		$enrollment = $this->enrollments->save(
			new EnrollmentRecord(
				id: null,
				flow_id: $flow->id,
				customer_email: $email,
				status: EnrollmentRecord::STATUS_ACTIVE,
				current_step: 0,
				next_run_at: null,
				created_at: $this->clock->now_mysql(),
				source: $source,
			)
		);

		$run_at = $this->clock->now() + $flow->steps[0]->delay;

		$enrollment = $this->enrollments->save(
			$enrollment->with_progress( 0, gmdate( 'Y-m-d H:i:s', $run_at ) )
		);

		$this->scheduler->schedule( $run_at, (int) $enrollment->id, 0 );

		return $enrollment;
	}
}
