<?php
/**
 * WooCommerce-backed CustomerActivity.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

/**
 * Answers activity questions from WooCommerce order data via wc_get_orders().
 */
final class WooCustomerActivity implements CustomerActivity {

	public function has_ordered_since( string $email, int $since ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$orders = \wc_get_orders(
			array(
				'billing_email' => $email,
				'date_created'  => '>=' . $since,
				'limit'         => 1,
				'return'        => 'ids',
				'status'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			)
		);

		return ! empty( $orders );
	}

	public function order_count( string $email ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = \wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => -1,
				'return'        => 'ids',
				'status'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' ),
			)
		);

		return is_array( $orders ) ? count( $orders ) : 0;
	}
}
