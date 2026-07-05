<?php
/**
 * WooCommerce-backed LapsedCustomerFinder.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Finds lapsed customers by targeting the right cohort directly: those who
 * ordered within a bounded lookback window ending at the cutoff, minus anyone
 * who has ordered since the cutoff. This includes old-order customers (the
 * win-back audience) instead of excluding them.
 *
 * The lookback window (how far back to consider a customer worth winning back)
 * is filterable; the boundary is deliberate and documented rather than a silent
 * order-count cap.
 */
final class WooLapsedCustomerFinder implements LapsedCustomerFinder {

	/** Default: only try to win back customers who lapsed within the last year. */
	private const DEFAULT_LOOKBACK = 31536000;

	public function lapsed_before( int $cutoff ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$lookback = (int) \apply_filters( 'flowforge_win_back_lookback', self::DEFAULT_LOOKBACK );
		$since    = $cutoff - $lookback;

		// Customers who ordered within [since, cutoff): candidate lapsed set.
		$candidates = $this->emails_in_range( $since, $cutoff );
		if ( array() === $candidates ) {
			return array();
		}

		// Customers who have ordered at or after the cutoff: not lapsed.
		$recent = $this->emails_in_range( $cutoff, null );

		return $this->diff( $candidates, $recent );
	}

	/**
	 * Distinct billing emails for orders created in [from, to).
	 *
	 * @return list<string>
	 */
	private function emails_in_range( int $from, ?int $to ): array {
		$date = null === $to ? '>=' . $from : $from . '...' . $to;

		$orders = \wc_get_orders(
			array(
				'limit'        => -1,
				'return'       => 'objects',
				'date_created' => $date,
				'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			)
		);

		$emails = array();
		foreach ( (array) $orders as $order ) {
			$email = strtolower( trim( (string) $order->get_billing_email() ) );
			if ( '' !== $email ) {
				$emails[ $email ] = true;
			}
		}

		return array_keys( $emails );
	}

	/**
	 * Emails in $candidates that are not in $recent.
	 *
	 * @param list<string> $candidates
	 * @param list<string> $recent
	 * @return list<string>
	 */
	private function diff( array $candidates, array $recent ): array {
		$recent_set = array_fill_keys( $recent, true );
		$lapsed     = array();
		foreach ( $candidates as $email ) {
			if ( ! isset( $recent_set[ $email ] ) ) {
				$lapsed[] = $email;
			}
		}
		return $lapsed;
	}
}
