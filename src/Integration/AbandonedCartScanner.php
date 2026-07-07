<?php
/**
 * Scans for abandoned carts and enrolls them into the abandoned-cart flow.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Engine\Enroller;
use CartQuill\Persistence\CartCaptureStore;
use CartQuill\Persistence\FlowRepository;
use CartQuill\Support\Clock;

/**
 * Runs on a recurring Action Scheduler tick. A capture is "abandoned" once it
 * has gone $threshold seconds without converting; each such customer is enrolled
 * into every active abandoned-cart flow, then marked enrolled so it is not
 * scanned again. If no abandoned-cart flow is active yet (templates arrive in a
 * later slice) captures are left pending.
 */
final class AbandonedCartScanner {

	public const FLOW_TYPE = 'abandoned_cart';

	/** Recurring Action Scheduler hook that runs the scan. */
	public const HOOK = 'cartquill_scan_abandoned_carts';

	/** How often the scan runs (seconds). */
	public const SCAN_INTERVAL = 900;

	/** Default idle time before a cart counts as abandoned (seconds). */
	public const DEFAULT_THRESHOLD = 3600;

	public function __construct(
		private readonly CartCaptureStore $captures,
		private readonly FlowRepository $flows,
		private readonly Enroller $enroller,
		private readonly Clock $clock,
	) {}

	/**
	 * @param int $threshold_seconds How long a cart sits idle before it counts
	 *                               as abandoned.
	 *
	 * @return int Number of enrollments created.
	 */
	public function scan( int $threshold_seconds ): int {
		$flows = $this->flows->active_by_type( self::FLOW_TYPE );
		if ( array() === $flows ) {
			return 0;
		}

		$cutoff   = gmdate( 'Y-m-d H:i:s', $this->clock->now() - $threshold_seconds );
		$enrolled = 0;

		foreach ( $this->captures->due( $cutoff ) as $email ) {
			// Carry the cart total into the enrollment so value-based recovery
			// gating (e.g. cart_value_gt) can read it.
			$capture = $this->captures->find( $email );
			$context = array( 'cart_value' => null !== $capture ? $capture->cart_value : 0.0 );

			foreach ( $flows as $flow ) {
				if ( null !== $this->enroller->enroll( $flow, $email, 'abandoned_cart', $context ) ) {
					++$enrolled;
				}
			}
			$this->captures->mark_enrolled( $email );
		}

		return $enrolled;
	}
}
