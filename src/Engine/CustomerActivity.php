<?php
/**
 * Read seam for WooCommerce customer activity used by step conditions.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Answers the questions step conditions ask about a customer, so the engine
 * never queries WooCommerce directly. Backed by WooCommerce order data at
 * runtime; a fake in tests.
 */
interface CustomerActivity {

	/**
	 * Whether the customer placed an order at or after $since (Unix timestamp).
	 *
	 * Drives exit-on-conversion: if they ordered since enrolling, the flow ends.
	 */
	public function has_ordered_since( string $email, int $since ): bool;

	/**
	 * Total number of orders this customer has placed.
	 *
	 * Drives first-order detection for the welcome flow (count of 1 means the
	 * order that just triggered is their first).
	 */
	public function order_count( string $email ): int;
}
