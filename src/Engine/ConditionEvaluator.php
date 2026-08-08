<?php
/**
 * Decides whether a step should send, skip, or exit the flow.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\EnrollmentRecord;

/**
 * Evaluates a step's conditions against live customer activity and the
 * enrollment's trigger-time context.
 *
 * Conditions are a list of `{type, …}` maps of two kinds:
 *
 * - `exit_if_ordered` — the exit-on-conversion guard used by abandoned-cart and
 *   win-back flows. `action` defaults to `exit` (stop the flow) but may be
 *   `skip` (advance past this one step without sending).
 * - **Skip-unless-satisfied gates** — the step runs only when a predicate holds;
 *   otherwise the step is skipped (the flow advances) rather than exited. The
 *   generic `require_context` gate tests the enrollment's captured context, so
 *   recipes can gate a step on a value: `{type:'require_context', key:'phone'}`
 *   requires a phone; `{type:'require_context', key:'cart_value', gt:50}`
 *   requires a value above a threshold. `first_time_customer` gates on the
 *   buyer's order count (their first order). Later slices add more gates.
 *
 * A step with no conditions always proceeds. Unknown condition types are
 * ignored (proceed) rather than silently dropping the customer from the flow.
 */
final class ConditionEvaluator {

	public const PROCEED = 'proceed';
	public const SKIP    = 'skip';
	public const EXIT    = 'exit';

	/**
	 * Skip-unless-satisfied gate types — the data-driven branching conditions. This
	 * are the data-driven gates the builder lists as conditions:
	 * the exit-on-conversion guard and plain delays are core drip primitives and are
	 * deliberately excluded.
	 */
	public const GATES = array( 'require_context', 'first_time_customer', 'marketing_opt_in', 'cart_value_gt', 'has_phone' );

	public function __construct( private readonly CustomerActivity $activity ) {}

	/**
	 * @return self::PROCEED|self::SKIP|self::EXIT
	 */
	public function decide( FlowStep $step, EnrollmentRecord $enrollment ): string {
		$since = $this->enrolled_at( $enrollment );

		foreach ( $step->conditions as $condition ) {
			$condition = (array) $condition;
			$type      = (string) ( $condition['type'] ?? '' );

			if ( 'exit_if_ordered' === $type ) {
				if ( $this->activity->has_ordered_since( $enrollment->customer_email, $since ) ) {
					return self::EXIT === ( $condition['action'] ?? self::EXIT ) ? self::EXIT : self::SKIP;
				}
				continue;
			}

			// A gate that is not satisfied skips this step; the flow advances.
			if ( in_array( $type, self::GATES, true ) && ! $this->gate_satisfied( $type, $condition, $enrollment ) ) {
				return self::SKIP;
			}
		}

		return self::PROCEED;
	}

	/**
	 * Whether a skip-unless-satisfied gate holds for this enrollment.
	 *
	 * @param array<string, mixed> $condition
	 */
	private function gate_satisfied( string $type, array $condition, EnrollmentRecord $enrollment ): bool {
		if ( 'require_context' === $type ) {
			$key   = (string) ( $condition['key'] ?? '' );
			$value = $enrollment->context[ $key ] ?? null;

			if ( array_key_exists( 'gt', $condition ) ) {
				return null !== $value && (float) $value > (float) $condition['gt'];
			}
			return ! empty( $value );
		}

		if ( 'first_time_customer' === $type ) {
			// The triggering order is included in the count, so one order means
			// this is the customer's first (matching the welcome trigger's rule).
			return $this->activity->order_count( $enrollment->customer_email ) <= 1;
		}

		if ( 'marketing_opt_in' === $type ) {
			return ! empty( $enrollment->context['marketing_opt_in'] );
		}

		if ( 'cart_value_gt' === $type ) {
			$threshold = (float) ( $condition['value'] ?? 0 );
			return (float) ( $enrollment->context['cart_value'] ?? 0 ) > $threshold;
		}

		if ( 'has_phone' === $type ) {
			return '' !== trim( (string) ( $enrollment->context['phone'] ?? '' ) );
		}

		return true;
	}

	private function enrolled_at( EnrollmentRecord $enrollment ): int {
		if ( null === $enrollment->created_at ) {
			return 0;
		}
		$ts = strtotime( $enrollment->created_at . ' UTC' );
		return false !== $ts ? $ts : 0;
	}
}
