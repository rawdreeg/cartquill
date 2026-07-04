<?php
/**
 * Scans for lapsed customers and enrolls them into the win-back flow.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Integration;

use FlowForge\Engine\Enroller;
use FlowForge\Engine\LapsedCustomerFinder;
use FlowForge\Flow\DefaultFlows;
use FlowForge\Persistence\EnrollmentRepository;
use FlowForge\Persistence\FlowRepository;
use FlowForge\Support\Clock;

/**
 * Runs on a recurring (daily) Action Scheduler tick. A customer whose most
 * recent order is older than the threshold counts as lapsed and is enrolled
 * into every active win-back flow — once per flow (has_any guard), so they are
 * not re-engaged every scan. A new order exits them via the flow's
 * exit_if_ordered condition.
 */
final class WinBackScanner {

	public const FLOW_TYPE = DefaultFlows::TYPE_WIN_BACK;

	public const HOOK = 'flowforge_scan_win_back';

	/** Daily. */
	public const SCAN_INTERVAL = 86400;

	/** Default lapse window: 90 days. */
	public const DEFAULT_THRESHOLD = 7776000;

	public function __construct(
		private readonly LapsedCustomerFinder $finder,
		private readonly FlowRepository $flows,
		private readonly EnrollmentRepository $enrollments,
		private readonly Enroller $enroller,
		private readonly Clock $clock,
	) {}

	/**
	 * @param int $threshold_seconds How long since the last order before a
	 *                               customer counts as lapsed.
	 *
	 * @return int Number of enrollments created.
	 */
	public function scan( int $threshold_seconds ): int {
		$flows = $this->flows->active_by_type( self::FLOW_TYPE );
		if ( array() === $flows ) {
			return 0;
		}

		$cutoff   = $this->clock->now() - $threshold_seconds;
		$enrolled = 0;

		foreach ( $this->finder->lapsed_before( $cutoff ) as $email ) {
			foreach ( $flows as $flow ) {
				if ( $this->enrollments->has_any( (int) $flow->id, $email ) ) {
					continue; // Already win-backed for this flow.
				}
				if ( null !== $this->enroller->enroll( $flow, $email, 'win_back' ) ) {
					++$enrolled;
				}
			}
		}

		return $enrolled;
	}
}
