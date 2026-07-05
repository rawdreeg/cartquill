<?php
/**
 * Captures the customer's email as early as possible and marks recovery.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Persistence\CartCaptureStore;
use FlowForge\Support\Clock;

/**
 * Email capture is the hardest data bit for abandoned cart, so this grabs it at
 * the earliest reliable points without over-engineering:
 *
 * - a logged-in customer's account email when they add to cart;
 * - a guest's billing email as they fill in checkout.
 *
 * When an order is placed the capture is marked recovered so the customer is
 * not chased. (Mid-flow conversion is also caught by the flow's exit_if_ordered
 * condition in the engine.)
 */
final class AbandonedCartTracker {

	public function __construct(
		private readonly CartCaptureStore $captures,
		private readonly Clock $clock,
	) {}

	public function register(): void {
		\add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ) );
		\add_action( 'woocommerce_checkout_update_order_review', array( $this, 'on_checkout_update' ) );
		\add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_placed' ), 10, 1 );
	}

	/**
	 * Capture a valid email as a pending cart.
	 */
	public function capture( string $email ): void {
		$email = trim( $email );
		if ( '' === $email || ! \is_email( $email ) ) {
			return;
		}
		$this->captures->capture( strtolower( $email ), $this->clock->now_mysql() );
	}

	public function on_add_to_cart(): void {
		if ( ! \is_user_logged_in() ) {
			return;
		}
		$user = \wp_get_current_user();
		if ( $user && ! empty( $user->user_email ) ) {
			$this->capture( (string) $user->user_email );
		}
	}

	/**
	 * @param string $post_data URL-encoded checkout form data.
	 */
	public function on_checkout_update( $post_data ): void {
		$parsed = array();
		parse_str( (string) $post_data, $parsed );
		if ( ! empty( $parsed['billing_email'] ) ) {
			$this->capture( (string) $parsed['billing_email'] );
		}
	}

	/**
	 * @param int $order_id The order that was placed.
	 */
	public function on_order_placed( $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( $email ) {
			$this->captures->recover( strtolower( (string) $email ) );
		}
	}
}
