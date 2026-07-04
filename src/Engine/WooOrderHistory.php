<?php
/**
 * WooCommerce-backed OrderHistory.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

/**
 * Reads order history from WooCommerce. Kept intentionally simple; a large store
 * would want a targeted query, but the win-back scan runs on a slow recurring
 * tick so a bounded lookback is acceptable for v1.
 */
final class WooOrderHistory implements OrderHistory {

	/** How many recent orders to inspect per scan. */
	private const LOOKBACK_LIMIT = 500;

	/**
	 * @var array<string, int>|null Cached email => latest order timestamp.
	 */
	private ?array $latest = null;

	public function customer_emails(): array {
		return array_keys( $this->latest_by_email() );
	}

	public function last_order_at( string $email ): ?int {
		$map   = $this->latest_by_email();
		$email = strtolower( trim( $email ) );
		return $map[ $email ] ?? null;
	}

	/**
	 * @return array<string, int>
	 */
	private function latest_by_email(): array {
		if ( null !== $this->latest ) {
			return $this->latest;
		}

		$this->latest = array();
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->latest;
		}

		$orders = \wc_get_orders(
			array(
				'limit'   => self::LOOKBACK_LIMIT,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			)
		);

		foreach ( (array) $orders as $order ) {
			$email = strtolower( (string) $order->get_billing_email() );
			if ( '' === $email ) {
				continue;
			}
			$created = $order->get_date_created();
			$ts      = $created ? (int) $created->getTimestamp() : 0;
			// Orders arrive newest-first, so the first seen per email is the latest.
			if ( ! isset( $this->latest[ $email ] ) || $ts > $this->latest[ $email ] ) {
				$this->latest[ $email ] = $ts;
			}
		}

		return $this->latest;
	}
}
