<?php
/**
 * Finds customers who have lapsed (no order since a cutoff).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The win-back scan's read seam. Taking the cutoff lets the WooCommerce
 * implementation query for lapsed customers directly — rather than scanning
 * recent orders, which would exclude exactly the old-order customers win-back
 * targets.
 *
 * Reads are bounded and resumable so a store with tens of thousands of orders
 * never hydrates them all in a single Action Scheduler tick: each call examines
 * at most $limit candidate orders and returns a {@see LapsedBatch} the scanner
 * pages through across ticks.
 */
interface LapsedCustomerFinder {

	/**
	 * A bounded page of customers whose most recent order is strictly before
	 * $cutoff (Unix timestamp) — i.e. they ordered before but not since.
	 *
	 * @param int $cutoff Lapse boundary as a Unix timestamp.
	 * @param int $limit  Maximum candidate orders to examine this call; 0 means
	 *                    unbounded (small stores and the in-memory finder).
	 * @param int $offset Opaque resume cursor from a prior batch; 0 to start.
	 */
	public function lapsed_before( int $cutoff, int $limit = 0, int $offset = 0 ): LapsedBatch;
}
