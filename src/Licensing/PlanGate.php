<?php
/**
 * Enforces a subscription tier's feature caps at flow activation.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\FlowRepository;
use CartQuill\Rest\FlowBuilderController;

/**
 * The tier caps that bite at *activation* (not at send time, which the meter
 * owns): how many workflows may be active at once, and whether a flow may use
 * conditional logic. Both read the plan's numeric {@see License::limits()}, so a
 * `cartquill_plan_limits` override reaches them through the same seam the meter
 * uses.
 *
 * Only the transition *into* the active status is gated — a draft or paused flow
 * is always saveable, and re-saving an already-active flow does not count against
 * its own workflow slot. "Conditional logic" means the data-driven branching gates
 * ({@see ConditionEvaluator::GATES}); the exit-on-conversion guard and plain delays
 * are core drip primitives available on every tier.
 */
final class PlanGate {

	public const REASON_WORKFLOW_CAP      = 'workflow_cap';
	public const REASON_CONDITIONAL_LOGIC = 'conditional_logic';

	public function __construct(
		private readonly License $license,
		private readonly FlowRepository $flows,
	) {}

	/**
	 * The active-workflow cap for the held plan (0 = unlimited).
	 */
	public function workflow_limit(): int {
		return max( 0, (int) ( $this->license->limits()['workflows'] ?? 0 ) );
	}

	/**
	 * Whether the held plan unlocks conditional-logic (branching) steps.
	 */
	public function conditional_logic_enabled(): bool {
		return 0 !== (int) ( $this->license->limits()['conditional_logic'] ?? 0 );
	}

	/**
	 * Why $flow may not be activated under the held plan, or '' if it may. The
	 * conditional-logic entitlement is checked before the workflow cap so the
	 * blocked reason names the more specific limitation.
	 */
	public function activation_error( FlowRecord $flow ): string {
		if ( ! $flow->is_active() ) {
			return ''; // Only activation is gated.
		}

		if ( ! $this->conditional_logic_enabled() && self::uses_conditional_logic( $flow ) ) {
			return self::REASON_CONDITIONAL_LOGIC;
		}

		$limit = $this->workflow_limit();
		if ( $limit > 0 && $this->active_count_excluding( $flow->id ) >= $limit ) {
			return self::REASON_WORKFLOW_CAP;
		}

		return '';
	}

	/**
	 * Apply the gate to a pending save. Returns the record to persist and the block
	 * reason ('' when the save is allowed as-is). A flow the held plan may not run
	 * active is kept out of the active status — reverted to a safe status — while its
	 * edits are always preserved. The shared rule behind both the admin editor's
	 * save and the builder's REST save.
	 *
	 * @param FlowRecord      $candidate The record the caller wants to persist.
	 * @param FlowRecord|null $current   The stored record being replaced, if any.
	 *
	 * @return array{record: FlowRecord, blocked: string}
	 */
	public function enforce( FlowRecord $candidate, ?FlowRecord $current = null ): array {
		$blocked = $candidate->is_active() ? $this->activation_error( $candidate ) : '';
		if ( '' === $blocked ) {
			return array(
				'record'  => $candidate,
				'blocked' => '',
			);
		}

		if ( null !== $current && $current->is_active() ) {
			$safe_status = FlowRecord::STATUS_PAUSED;
		} elseif ( null !== $current ) {
			$safe_status = $current->status;
		} else {
			$safe_status = FlowRecord::STATUS_DRAFT;
		}

		return array(
			'record'  => $candidate->with_status( $safe_status ),
			'blocked' => $blocked,
		);
	}

	/**
	 * Hook the gate onto the builder's pre-save seam. Absent this call — which is
	 * what the WordPress.org plugin ships — the filter has no listener and every
	 * save is persisted exactly as the builder sent it.
	 */
	public function register(): void {
		\add_filter( FlowBuilderController::FILTER_PRESAVE, array( $this, 'presave_filter' ), 10, 3 );
	}

	/**
	 * Apply {@see self::enforce()} to a pending builder save and translate the block
	 * reason into the message the builder shows.
	 *
	 * @param array{record: FlowRecord, blocked: string} $pending   The pending save.
	 * @param FlowRecord                                 $candidate The record the builder sent.
	 * @param FlowRecord|null                            $existing  The stored record being replaced.
	 *
	 * @return array{record: FlowRecord, blocked: string}
	 */
	public function presave_filter( array $pending, FlowRecord $candidate, ?FlowRecord $existing ): array {
		$gated = $this->enforce( $candidate, $existing );

		return array(
			'record'  => $gated['record'],
			'blocked' => self::message_for( $gated['blocked'] ),
		);
	}

	/**
	 * The builder-facing message for a block reason ('' when nothing was blocked).
	 */
	private static function message_for( string $reason ): string {
		switch ( $reason ) {
			case self::REASON_WORKFLOW_CAP:
				return \__( 'The active-workflow limit for your plan was reached, so the flow was saved but not activated.', 'cartquill' );
			case self::REASON_CONDITIONAL_LOGIC:
				return \__( 'Conditional logic needs a higher plan, so the flow was saved but not activated.', 'cartquill' );
			default:
				return '';
		}
	}

	/**
	 * The number of currently-active flows other than $flow_id — so re-activating a
	 * flow that is already active does not count against its own slot.
	 */
	private function active_count_excluding( ?int $flow_id ): int {
		$count = 0;
		foreach ( $this->flows->all() as $flow ) {
			if ( $flow->is_active() && $flow->id !== $flow_id ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Whether a flow carries a data-driven branching condition (the paid
	 * conditional-logic feature). The exit-on-conversion guard is not one.
	 */
	public static function uses_conditional_logic( FlowRecord $flow ): bool {
		foreach ( $flow->steps as $step ) {
			foreach ( $step->conditions as $condition ) {
				$type = (string) ( ( (array) $condition )['type'] ?? '' );
				if ( in_array( $type, ConditionEvaluator::GATES, true ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
