<?php
/**
 * The engine's per-step pipeline.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Action\ActionContext;
use CartQuill\Action\ActionRegistry;
use CartQuill\Action\EmailAction;
use CartQuill\Compliance\SuppressionList;
use CartQuill\Flow\FlowDefinition;
use CartQuill\Metering\Meter;
use CartQuill\Metering\NullMeter;
use CartQuill\Flow\FlowStep;
use CartQuill\Model\SendResult;
use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Persistence\EnrollmentRepository;
use CartQuill\Persistence\FlowRepository;
use CartQuill\Persistence\MessageRecord;
use CartQuill\Persistence\MessageRepository;
use CartQuill\Scheduling\Scheduler;
use CartQuill\Sender\SenderInterface;
use CartQuill\Support\Clock;

/**
 * Runs one step of one enrollment, then schedules the next.
 *
 * Pipeline (the locked order): guard active + ordering → load flow/step →
 * resolve the step's typed action → check suppression → check conditions
 * (exit/skip on conversion, skip-unless-satisfied gates) → claim → execute the
 * action → record message → schedule next step (or complete).
 *
 * Every step runs a typed {@see \CartQuill\Action\ActionInterface} resolved from
 * the {@see ActionRegistry}. Core always provides the `email` action (which
 * wraps the compose → {@see SenderInterface::send()} path unchanged), so the
 * email path — sending, unsubscribe, tracking, attribution — is untouched;
 * add-ons register Slack/Sheets/Mailchimp/SMS actions on the registry.
 *
 * An unknown or unavailable action (add-on absent, unlicensed, connection down)
 * is permanently dead-lettered so the flow advances instead of stalling. A send
 * that fails or throws is retried with bounded backoff on the same step; only
 * after the retry budget is exhausted is the step dead-lettered (recorded
 * failed, failure surfaced via `cartquill_step_send_failed`) and the flow
 * advanced. Idempotency: a step whose message is already settled never re-sends;
 * the DB's unique (enrollment_id, step_index) index is the final backstop.
 */
final class StepRunner {

	/** Total send attempts before a step is dead-lettered. */
	private const MAX_ATTEMPTS = 3;

	/** Retry backoff base in seconds; doubles each attempt, capped at RETRY_CAP. */
	private const RETRY_BASE = 300;
	private const RETRY_CAP  = 3600;

	private readonly ActionRegistry $actions;
	private readonly Meter $meter;

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly EnrollmentRepository $enrollments,
		private readonly MessageRepository $messages,
		MessageComposer $composer,
		SenderInterface $sender,
		private readonly SuppressionList $suppression,
		private readonly ConditionEvaluator $conditions,
		private readonly Scheduler $scheduler,
		private readonly Clock $clock,
		?ActionRegistry $actions = null,
		?Meter $meter = null,
	) {
		$this->actions = $actions ?? new ActionRegistry();
		$this->meter   = $meter ?? new NullMeter();

		// Core always provides the email action (the default channel), built from
		// the injected composer + active sender, so the email path is unchanged
		// whether or not an add-on registry is supplied.
		if ( null === $this->actions->get( EmailAction::TYPE ) ) {
			$this->actions->register( new EmailAction( $composer, $sender ) );
		}
	}

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

		// Resolve the step's typed action. An unavailable action never stalls the
		// flow: it is dead-lettered and the flow advances.
		$action = $this->actions->get( $step->action );
		if ( null === $action ) {
			$this->dead_letter_unavailable( $enrollment, $existing, $step, $step_index, $definition );
			return;
		}

		$context = new ActionContext(
			step: $step,
			customer_email: $enrollment->customer_email,
			flow_id: $definition->id,
			step_index: $step_index,
			enrollment_id: $enrollment_id,
			context: $enrollment->context,
		);

		// Suppression is the first thing every customer-facing send checks (locked
		// order). Internal actions (Slack/Sheets/Mailchimp) reach no customer inbox
		// and skip it; SMS checks a phone key, email an email key.
		$target = $action->target( $context );
		if ( $action->is_customer_facing()
			&& null !== $target
			&& $this->suppression->is_suppressed( $target, $action->type() )
		) {
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

		// Enforce the monthly action cap before executing (fail-closed): an
		// over-cap step defers to the next period rather than consuming a billed
		// action or dropping the enrolled customer.
		if ( $this->meter->would_exceed() ) {
			$this->defer_to_next_period( $enrollment, $step_index );
			return;
		}

		if ( null !== $existing ) {
			$claimed = $existing; // retry this step's own queued row
		} else {
			// Reserve the (enrollment, step) slot atomically before executing. If a
			// concurrent worker already claimed it, abort — no double-send.
			$claimed = $this->messages->claim(
				new MessageRecord(
					id: null,
					enrollment_id: $enrollment_id,
					flow_id: $definition->id,
					step_index: $step_index,
					recipient: $enrollment->customer_email,
					sender: $action->sender_key(),
					status: MessageRecord::STATUS_QUEUED,
					channel: $action->type(),
					target: $target,
				)
			);
			if ( null === $claimed ) {
				return;
			}
		}

		// Any throw between the claim and the result is treated as a failed
		// attempt, so the claimed row is never left stranded queued.
		try {
			$result = $action->execute( $context->with_message_id( (int) $claimed->id ) );
		} catch ( \Throwable $e ) {
			$result = SendResult::failed( $e->getMessage() );
		}

		if ( $result->is_accepted() ) {
			$this->messages->save(
				$claimed->with_result( MessageRecord::STATUS_SENT, $result->external_id, $this->clock->now_mysql() )
			);
			$this->meter->increment(); // count exactly one executed action, any channel
			$this->advance( $enrollment, $step_index, $definition );
			return;
		}

		$this->handle_failure( $enrollment, $claimed, $step_index, $definition, $result->error );
	}

	/**
	 * Permanently dead-letter a step whose action is unavailable, then advance —
	 * the flow must not stall on a missing add-on/connection. The (enrollment,
	 * step) slot is claimed (or the existing row reused) and recorded failed so a
	 * re-run is idempotent.
	 */
	private function dead_letter_unavailable( EnrollmentRecord $enrollment, ?MessageRecord $existing, FlowStep $step, int $step_index, FlowDefinition $definition ): void {
		$claimed = $existing;
		if ( null === $claimed ) {
			$claimed = $this->messages->claim(
				new MessageRecord(
					id: null,
					enrollment_id: (int) $enrollment->id,
					flow_id: $definition->id,
					step_index: $step_index,
					recipient: $enrollment->customer_email,
					sender: '',
					status: MessageRecord::STATUS_QUEUED,
					channel: $step->action,
					target: null,
				)
			);
			if ( null === $claimed ) {
				return; // lost the claim to a concurrent worker
			}
		}

		$this->messages->save(
			$claimed->with_attempt( self::MAX_ATTEMPTS )->with_result( MessageRecord::STATUS_FAILED, $claimed->external_id, $this->clock->now_mysql() )
		);
		$this->surface_failure( (int) $enrollment->id, $step_index, self::MAX_ATTEMPTS, true, sprintf( "action '%s' unavailable", $step->action ) );
		$this->advance( $enrollment, $step_index, $definition );
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
		\do_action( 'cartquill_step_send_failed', $enrollment_id, $step_index, $attempts, $exhausted, $error );
	}

	/**
	 * Defer an over-cap step to the start of the next billing period. The step is
	 * neither claimed nor executed (no billed action), the enrollment keeps its
	 * place, and the cap-reached hook fires for admin surfacing.
	 */
	private function defer_to_next_period( EnrollmentRecord $enrollment, int $step_index ): void {
		$run_at = $this->next_period_start();
		$this->enrollments->save(
			$enrollment->with_progress( $step_index, gmdate( 'Y-m-d H:i:s', $run_at ) )
		);
		$this->scheduler->schedule( $run_at, (int) $enrollment->id, $step_index );

		if ( function_exists( 'do_action' ) ) {
			\do_action( 'cartquill_action_cap_reached', (int) $enrollment->id, $step_index );
		}
	}

	/**
	 * Unix timestamp for the start of the next UTC month (when the cap resets).
	 */
	private function next_period_start(): int {
		$now   = $this->clock->now();
		$year  = (int) gmdate( 'Y', $now );
		$month = (int) gmdate( 'n', $now ) + 1;
		if ( $month > 12 ) {
			$month = 1;
			++$year;
		}
		return (int) gmmktime( 0, 0, 0, $month, 1, $year );
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
