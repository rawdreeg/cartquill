<?php
/**
 * The engine's per-step pipeline.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Compliance\SuppressionList;
use FlowForge\Flow\FlowDefinition;
use FlowForge\Model\SendResult;
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
 * Pipeline (the locked order): guard active + ordering → load flow/step →
 * check suppression → check conditions (exit/skip on conversion) → render →
 * send → record message → schedule next step (or complete).
 *
 * A send that fails or throws is retried with bounded backoff on the same step;
 * only after the retry budget is exhausted is the step dead-lettered (recorded
 * failed, failure surfaced via the `flowforge_step_send_failed` action) and the
 * flow advanced. Idempotency: a step whose message is already settled (sent or
 * dead-lettered) never re-sends; a queued row is either a foreign/in-flight
 * pre-send claim (left alone) or this step's own failed attempt awaiting retry.
 * The current_step ordering check ignores stale jobs and the DB's unique
 * (enrollment_id, step_index) index is the final backstop.
 */
final class StepRunner {

	/** Total send attempts before a step is dead-lettered. */
	private const MAX_ATTEMPTS = 3;

	/** Retry backoff base in seconds; doubles each attempt, capped at RETRY_CAP. */
	private const RETRY_BASE = 300;
	private const RETRY_CAP  = 3600;

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

		// A settled message (sent or dead-lettered) means this step is done. A
		// queued row with no failed attempt yet is a foreign/in-flight pre-send
		// claim we stay out of; a queued row that has already failed is ours to
		// retry.
		$existing = $this->messages->find_for_step( $enrollment_id, $step_index );
		if ( null !== $existing ) {
			if ( MessageRecord::STATUS_QUEUED !== $existing->status || $existing->attempts < 1 ) {
				return;
			}
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

		if ( null !== $existing ) {
			$claimed = $existing; // retry this step's own queued row
		} else {
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
		}

		// Any throw between the claim and the result is treated as a failed
		// attempt, so the claimed row is never left stranded queued.
		try {
			$message = $this->composer->compose(
				$step,
				$enrollment->customer_email,
				$definition->id,
				$step_index,
				$enrollment_id,
				(int) $claimed->id
			);
			$result = $this->sender->send( $message );
		} catch ( \Throwable $e ) {
			$result = SendResult::failed( $e->getMessage() );
		}

		if ( $result->is_accepted() ) {
			$this->messages->save(
				$claimed->with_result( MessageRecord::STATUS_SENT, $result->external_id, $this->clock->now_mysql() )
			);
			$this->advance( $enrollment, $step_index, $definition );
			return;
		}

		$this->handle_failure( $enrollment, $claimed, $step_index, $definition, $result->error );
	}

	/**
	 * Retry a failed step with bounded backoff, or dead-letter it and advance
	 * the flow once the retry budget is exhausted. Either way the failure is
	 * surfaced, never silently dropped.
	 */
	private function handle_failure( EnrollmentRecord $enrollment, MessageRecord $claimed, int $step_index, FlowDefinition $definition, ?string $error ): void {
		$attempts = $claimed->attempts + 1;

		if ( $attempts < self::MAX_ATTEMPTS ) {
			$this->messages->save( $claimed->with_attempt( $attempts ) );
			$this->scheduler->schedule( $this->clock->now() + $this->backoff( $attempts ), (int) $enrollment->id, $step_index );
			$this->surface_failure( (int) $enrollment->id, $step_index, $attempts, false, $error );
			return;
		}

		$this->messages->save(
			$claimed->with_attempt( $attempts )->with_result( MessageRecord::STATUS_FAILED, $claimed->external_id, $this->clock->now_mysql() )
		);
		$this->surface_failure( (int) $enrollment->id, $step_index, $attempts, true, $error );
		$this->advance( $enrollment, $step_index, $definition );
	}

	/**
	 * Backoff in seconds before the next retry (exponential, capped).
	 */
	private function backoff( int $attempts ): int {
		return (int) min( self::RETRY_BASE * ( 2 ** ( $attempts - 1 ) ), self::RETRY_CAP );
	}

	/**
	 * Surface a send failure for monitoring/admin health. Fires on every failed
	 * attempt; $exhausted is true on the final one, when the step is dead-lettered.
	 */
	private function surface_failure( int $enrollment_id, int $step_index, int $attempts, bool $exhausted, ?string $error ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}
		\do_action( 'flowforge_step_send_failed', $enrollment_id, $step_index, $attempts, $exhausted, $error );
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
