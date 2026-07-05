<?php
/**
 * Scans for lapsed customers and enrolls them into the win-back flow.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Engine\Enroller;
use CartQuill\Engine\LapsedCustomerFinder;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Persistence\EnrollmentRepository;
use CartQuill\Persistence\FlowRepository;
use CartQuill\Support\Clock;
use CartQuill\Support\ScanCursor;

/**
 * Runs on a recurring (daily) Action Scheduler tick. A customer whose most
 * recent order is older than the threshold counts as lapsed and is enrolled
 * into every active win-back flow — once per flow (has_any guard), so they are
 * not re-engaged every scan. A new order exits them via the flow's
 * exit_if_ordered condition.
 *
 * Scale: the finder is read one bounded page ({@see BATCH_SIZE} candidate
 * orders) per tick. When a page is full the scanner parks its cursor and
 * enqueues an async continuation so a large backlog drains across several ticks
 * instead of hydrating every order at once. The cursor is cleared only once the
 * window drains, so a completed run starts the next daily scan fresh from the
 * beginning, while a tick that stalls mid-drain simply resumes from the parked
 * offset on the next run. Re-enrollment is idempotent (has_any), so cursor
 * overlap across a shifting order set never double-sends, and the periodic
 * fresh full scan recovers anyone skipped at a page boundary.
 */
final class WinBackScanner {

	public const FLOW_TYPE = DefaultFlows::TYPE_WIN_BACK;

	public const HOOK = 'cartquill_scan_win_back';

	/** Daily. */
	public const SCAN_INTERVAL = 86400;

	/** Default lapse window: 90 days. */
	public const DEFAULT_THRESHOLD = 7776000;

	/** Candidate orders examined per tick before yielding to the next tick. */
	public const BATCH_SIZE = 200;

	/** wp_option key for the cross-tick scan cursor. */
	public const CURSOR_OPTION = 'cartquill_win_back_cursor';

	public function __construct(
		private readonly LapsedCustomerFinder $finder,
		private readonly FlowRepository $flows,
		private readonly EnrollmentRepository $enrollments,
		private readonly Enroller $enroller,
		private readonly Clock $clock,
		private readonly ScanCursor $cursor,
	) {}

	/**
	 * @param int $threshold_seconds How long since the last order before a
	 *                               customer counts as lapsed.
	 *
	 * @return int Number of enrollments created this tick.
	 */
	public function scan( int $threshold_seconds ): int {
		$flows = $this->flows->active_by_type( self::FLOW_TYPE );
		if ( array() === $flows ) {
			$this->cursor->clear();
			return 0;
		}

		$cutoff = $this->clock->now() - $threshold_seconds;
		$batch  = $this->finder->lapsed_before( $cutoff, self::BATCH_SIZE, $this->cursor->get() );

		$enrolled = 0;
		foreach ( $batch->emails as $email ) {
			foreach ( $flows as $flow ) {
				if ( $this->enrollments->has_any( (int) $flow->id, $email ) ) {
					continue; // Already win-backed for this flow.
				}
				if ( null !== $this->enroller->enroll( $flow, $email, 'win_back' ) ) {
					++$enrolled;
				}
			}
		}

		if ( $batch->done ) {
			$this->cursor->clear();
		} else {
			$this->cursor->save( $batch->next_offset );
			$this->enqueue_continuation();
		}

		return $enrolled;
	}

	/**
	 * Ask Action Scheduler to run this hook again immediately so the backlog
	 * keeps draining without waiting a full day, while each tick stays bounded.
	 */
	private function enqueue_continuation(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			\as_enqueue_async_action( self::HOOK, array(), 'cartquill' );
		}
	}
}
