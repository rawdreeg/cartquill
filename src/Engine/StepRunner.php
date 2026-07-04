<?php
/**
 * The engine's per-step pipeline.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

use FlowForge\Compliance\SuppressionList;
use FlowForge\Flow\FlowDefinition;
use FlowForge\Persistence\EnrollmentRecord;
use FlowForge\Persistence\EnrollmentRepository;
use FlowForge\Persistence\FlowRepository;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Persistence\MessageRepository;
use FlowForge\Scheduling\Scheduler;
use FlowForge\Sender\SenderInterface;
use FlowForge\Support\Clock;

/**
 * Runs one step of one enrollment, then schedules the next.
 *
 * Pipeline (the locked order): guard active + not-already-sent → load flow/step
 * → check conditions (exit/skip on conversion) → check suppression → render →
 * send → record message → schedule next step (or complete).
 *
 * Idempotency has two guards: the message-exists check (never re-send a step
 * that already produced a message) and the current_step ordering check (ignore
 * stale/duplicate jobs). The DB's unique (enrollment_id, step_index) index is
 * the final backstop.
 */
final class StepRunner {

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly EnrollmentRepository $enrollments,
		private readonly MessageRepository $messages,
		private readonly MessageComposer $composer,
		private readonly SenderInterface $sender,
		private readonly SuppressionList $suppression,
		private readonly ConditionEvaluator $conditions,
		private readonly Scheduler $scheduler,
		private readonly Clock $clock,
	) {}

	public function run_step( int $enrollment_id, int $step_index ): void {
		$enrollment = $this->enrollments->find( $enrollment_id );
		if ( null === $enrollment || ! $enrollment->is_active() ) {
			return;
		}

		// Ordering guard: ignore stale or duplicate jobs.
		if ( $step_index !== $enrollment->current_step ) {
			return;
		}

		// Idempotency guard: a step that already produced a message never re-sends.
		if ( $this->messages->exists_for_step( $enrollment_id, $step_index ) ) {
			return;
		}

		$flow = $this->flows->find( $enrollment->flow_id );
		if ( null === $flow ) {
			return;
		}

		$definition = $flow->to_definition();
		$step       = $definition->step( $step_index );
		if ( null === $step ) {
			$this->complete( $enrollment, $step_index );
			return;
		}

		// Suppression is the first thing every send checks (locked order).
		if ( $this->suppression->is_suppressed( $enrollment->customer_email ) ) {
			$this->enrollments->save( $enrollment->with_status( EnrollmentRecord::STATUS_UNSUBSCRIBED ) );
			return;
		}

		$decision = $this->conditions->decide( $step, $enrollment );
		if ( ConditionEvaluator::EXIT === $decision ) {
			$this->enrollments->save( $enrollment->with_status( EnrollmentRecord::STATUS_EXITED ) );
			return;
		}

		if ( ConditionEvaluator::SKIP === $decision ) {
			$this->advance( $enrollment, $step_index, $definition );
			return;
		}

		// Reserve the (enrollment, step) slot atomically before sending. If a
		// concurrent worker already claimed it, abort — no double-send.
		$claimed = $this->messages->claim(
			new MessageRecord(
				id: null,
				enrollment_id: $enrollment_id,
				flow_id: $definition->id,
				step_index: $step_index,
				recipient: $enrollment->customer_email,
				sender: $this->sender->key(),
				status: MessageRecord::STATUS_QUEUED,
			)
		);
		if ( null === $claimed ) {
			return;
		}

		$message = $this->composer->compose(
			$step,
			$enrollment->customer_email,
			$definition->id,
			$step_index,
			$enrollment_id
		);

		$result = $this->sender->send( $message );

		$this->messages->save(
			$claimed->with_result(
				$result->is_accepted() ? MessageRecord::STATUS_SENT : MessageRecord::STATUS_FAILED,
				$result->external_id,
				$this->clock->now_mysql(),
			)
		);

		$this->advance( $enrollment, $step_index, $definition );
	}

	/**
	 * Schedule the next step, or complete the enrollment if there is none.
	 */
	private function advance( EnrollmentRecord $enrollment, int $step_index, FlowDefinition $definition ): void {
		$next = $step_index + 1;

		$next_step = $definition->step( $next );
		if ( null === $next_step ) {
			$this->complete( $enrollment, $next );
			return;
		}

		$run_at = $this->clock->now() + $next_step->delay;
		$this->enrollments->save(
			$enrollment->with_progress( $next, gmdate( 'Y-m-d H:i:s', $run_at ) )
		);
		$this->scheduler->schedule( $run_at, (int) $enrollment->id, $next );
	}

	private function complete( EnrollmentRecord $enrollment, int $final_step ): void {
		$this->enrollments->save(
			$enrollment->with_progress( $final_step, null )->with_status( EnrollmentRecord::STATUS_COMPLETED )
		);
	}
}
