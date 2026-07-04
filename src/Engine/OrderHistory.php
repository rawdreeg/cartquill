<?php
/**
 * Read seam over WooCommerce order history, used by the win-back scan.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

/**
 * Exposes just enough order history for lapsed-customer detection, keeping the
 * recency decision in tested engine code rather than in a WooCommerce query.
 */
interface OrderHistory {

	/**
	 * Every customer email that has placed at least one order.
	 *
	 * @return list<string>
	 */
	public function customer_emails(): array;

	/**
	 * Unix timestamp of the customer's most recent order, or null if none.
	 */
	public function last_order_at( string $email ): ?int;
}
