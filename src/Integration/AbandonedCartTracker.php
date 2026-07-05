<?php
/**
 * Captures the customer's email as early as possible and marks recovery.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Persistence\CartCaptureStore;
use CartQuill\Support\Clock;

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

	/** Max checkout-review captures per requester IP within the window. */
	private const CAPTURE_LIMIT  = 10;
	private const CAPTURE_WINDOW = 3600;

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
		// This fires from the nopriv checkout-review AJAX with raw attacker POST,
		// so only capture the billing email when the requester actually has a
		// cart in their session (no cart -> nothing captured).
		if ( ! $this->has_active_cart() ) {
			return;
		}

		$parsed = array();
		parse_str( (string) $post_data, $parsed );
		$email = isset( $parsed['billing_email'] ) ? (string) $parsed['billing_email'] : '';

		// Only spend rate-limit budget once there is actually an address to
		// capture — the review AJAX fires on every field change, most with no
		// email yet — and cap how many one IP can seed so the store can't be
		// used as a spam cannon.
		if ( '' === $email || ! $this->within_capture_rate_limit() ) {
			return;
		}

		$this->capture( $email );
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

	/**
	 * Whether the requester has a non-empty WooCommerce cart in their session.
	 */
	private function has_active_cart(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$wc = \WC();
		return $wc && isset( $wc->cart ) && $wc->cart && ! $wc->cart->is_empty();
	}

	/**
	 * Bound how many captures a single IP can seed within the window, so a
	 * scripted client cannot flood the store with third-party addresses. A
	 * best-effort soft throttle (a transient counter, not a hard atomic limit) —
	 * enough to defang the spam vector, which the cart requirement already gates.
	 */
	private function within_capture_rate_limit(): bool {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}
		$key   = 'cartquill_cc_rl_' . md5( $this->requester_ip() );
		$count = (int) \get_transient( $key );
		if ( $count >= self::CAPTURE_LIMIT ) {
			return false;
		}
		\set_transient( $key, $count + 1, self::CAPTURE_WINDOW );
		return true;
	}

	private function requester_ip(): string {
		// The raw value is only ever hashed into a rate-limit key, never output.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
