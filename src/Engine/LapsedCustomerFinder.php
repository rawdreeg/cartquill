<?php
/**
 * Finds customers who have lapsed (no order since a cutoff).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

/**
 * The win-back scan's read seam. Taking the cutoff lets the WooCommerce
 * implementation query for lapsed customers directly — rather than scanning
 * recent orders, which would exclude exactly the old-order customers win-back
 * targets.
 */
interface LapsedCustomerFinder {

	/**
	 * Emails of customers whose most recent order is strictly before $cutoff
	 * (Unix timestamp) — i.e. they have ordered before but not since.
	 *
	 * @return list<string>
	 */
	public function lapsed_before( int $cutoff ): array;
}
