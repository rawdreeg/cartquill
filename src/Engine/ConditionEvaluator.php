<?php
/**
 * Decides whether a step should send, skip, or exit the flow.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Flow\FlowStep;
use FlowForge\Persistence\EnrollmentRecord;

/**
 * Evaluates a step's conditions against live customer activity.
 *
 * Conditions are a list of `{type, action?}` maps. The supported type in the
 * engine slice is `exit_if_ordered` — the exit-on-conversion guard used by
 * abandoned-cart and win-back flows. `action` defaults to `exit` (stop the
 * flow) but may be `skip` (advance past this one step without sending).
 *
 * A step with no conditions always proceeds. Unknown condition types are
 * ignored (proceed) rather than silently dropping the customer from the flow.
 */
final class ConditionEvaluator {

	public const PROCEED = 'proceed';
	public const SKIP    = 'skip';
	public const EXIT    = 'exit';

	public function __construct( private readonly CustomerActivity $activity ) {}

	/**
	 * @return self::PROCEED|self::SKIP|self::EXIT
	 */
	public function decide( FlowStep $step, EnrollmentRecord $enrollment ): string {
		$since = $this->enrolled_at( $enrollment );

		foreach ( $step->conditions as $condition ) {
			$condition = (array) $condition;
			$type      = (string) ( $condition['type'] ?? '' );
			$action    = self::EXIT === ( $condition['action'] ?? self::EXIT ) ? self::EXIT : self::SKIP;

			if ( 'exit_if_ordered' === $type
				&& $this->activity->has_ordered_since( $enrollment->customer_email, $since )
			) {
				return $action;
			}
		}

		return self::PROCEED;
	}

	private function enrolled_at( EnrollmentRecord $enrollment ): int {
		if ( null === $enrollment->created_at ) {
			return 0;
		}
		$ts = strtotime( $enrollment->created_at . ' UTC' );
		return false !== $ts ? $ts : 0;
	}
}
