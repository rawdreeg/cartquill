<?php
/**
 * Enrolls a customer into a flow and schedules its first step.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Metering\Meter;
use CartQuill\Metering\NullMeter;
use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Persistence\EnrollmentRepository;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Scheduling\Scheduler;
use CartQuill\Support\Clock;

/**
 * The entry point every trigger calls. Creates an active enrollment at step 0
 * and schedules that step at now + its delay. Enrollment is idempotent: a
 * customer already running this flow is not enrolled again.
 */
final class Enroller {

	private readonly Meter $meter;

	public function __construct(
		private readonly EnrollmentRepository $enrollments,
		private readonly Scheduler $scheduler,
		private readonly Clock $clock,
		?Meter $meter = null,
	) {
		$this->meter = $meter ?? new NullMeter();
	}

	/**
	 * @param string               $source  Consent/trigger source recorded on the enrollment.
	 * @param array<string, mixed> $context Trigger-time data (order total, phone, opt-in, …)
	 *                                       captured for value-based step conditions.
	 *
	 * @return EnrollmentRecord|null The new enrollment, or null if the flow is
	 *                               not enrollable or the customer is already
	 *                               enrolled.
	 */
	public function enroll( FlowRecord $flow, string $email, string $source = '', array $context = array() ): ?EnrollmentRecord {
		if ( null === $flow->id || ! $flow->is_active() || array() === $flow->steps ) {
			return null;
		}

		if ( null !== $this->enrollments->find_active( $flow->id, $email ) ) {
			return null;
		}

		// Do not start a run the execution policy would immediately defer. CartQuill's
		// own meter never declines, so this is a no-op unless an extension supplies one.
		if ( $this->meter->would_exceed() ) {
			return null;
		}

		// Atomic create is the concurrency-safe backstop behind find_active:
		// two triggers firing at once cannot both create an active enrollment.
		$enrollment = $this->enrollments->create(
			new EnrollmentRecord(
				id: null,
				flow_id: $flow->id,
				customer_email: $email,
				status: EnrollmentRecord::STATUS_ACTIVE,
				current_step: 0,
				next_run_at: null,
				created_at: $this->clock->now_mysql(),
				source: $source,
				context: $context,
			)
		);
		if ( null === $enrollment ) {
			return null;
		}

		$run_at = $this->clock->now() + $flow->steps[0]->delay;

		$enrollment = $this->enrollments->save(
			$enrollment->with_progress( 0, gmdate( 'Y-m-d H:i:s', $run_at ) )
		);

		$this->scheduler->schedule( $run_at, (int) $enrollment->id, 0 );

		return $enrollment;
	}
}
