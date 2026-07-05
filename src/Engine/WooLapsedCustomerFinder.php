<?php
/**
 * WooCommerce-backed LapsedCustomerFinder.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Finds lapsed customers by targeting the right cohort directly: those who
 * ordered within a bounded lookback window ending at the cutoff, minus anyone
 * who has ordered since. This includes old-order customers (the win-back
 * audience) instead of excluding them.
 *
 * Reads are bounded so large stores stay within one Action Scheduler tick: a
 * single ranged query pulls at most $limit candidate order IDs (never the whole
 * table as objects), each is checked for a newer order with a one-row existence
 * query, and the caller resumes from {@see LapsedBatch::$next_offset} next tick.
 *
 * The lookback window (how far back to consider a customer worth winning back)
 * is filterable; the boundary is deliberate and documented rather than a silent
 * order-count cap.
 */
final class WooLapsedCustomerFinder implements LapsedCustomerFinder {

	/** Default: only try to win back customers who lapsed within the last year. */
	private const DEFAULT_LOOKBACK = 31536000;

	/** Order statuses that count as a real purchase. */
	private const STATUSES = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );

	public function lapsed_before( int $cutoff, int $limit = 0, int $offset = 0 ): LapsedBatch {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new LapsedBatch( array(), 0, true );
		}

		$lookback = (int) \apply_filters( 'cartquill_win_back_lookback', self::DEFAULT_LOOKBACK );
		$since    = $cutoff - $lookback;
		$page     = $limit > 0 ? $limit : -1;

		// One ranged pass over candidate orders (created in [since, cutoff)),
		// newest first, projected to IDs — never hydrating the whole table.
		$ids = \wc_get_orders(
			array(
				'return'       => 'ids',
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => $since . '...' . $cutoff,
				'status'       => self::STATUSES,
				'limit'        => $page,
				'offset'       => max( 0, $offset ),
			)
		);
		$ids = (array) $ids;

		$emails = array();
		$seen   = array();
		foreach ( $ids as $id ) {
			$order = \wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$email = strtolower( trim( (string) $order->get_billing_email() ) );
			if ( '' === $email || isset( $seen[ $email ] ) ) {
				continue;
			}
			$seen[ $email ] = true;
			// Lapsed only if there is no qualifying order at or after the cutoff.
			if ( $this->ordered_since( $email, $cutoff ) ) {
				continue;
			}
			$emails[ $email ] = true;
		}

		// A short page means the window is exhausted; a full page means resume.
		$done = $page < 0 || count( $ids ) < $page;

		return new LapsedBatch( array_keys( $emails ), max( 0, $offset ) + count( $ids ), $done );
	}

	/**
	 * Whether the customer has any qualifying order at or after $cutoff. Bounded
	 * to a single row so it never loads the customer's whole order history.
	 */
	private function ordered_since( string $email, int $cutoff ): bool {
		$ids = \wc_get_orders(
			array(
				'billing_email' => $email,
				'date_created'  => '>=' . $cutoff,
				'status'        => self::STATUSES,
				'return'        => 'ids',
				'limit'         => 1,
			)
		);

		return ! empty( $ids );
	}
}
