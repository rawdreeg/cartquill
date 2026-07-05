<?php
/**
 * Attributes an order's revenue to a flow when the order is placed.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Attribution\Attributor;

/**
 * On order placement, hands the order to the Attributor. The attribution window
 * is filterable and surfaced in the reporting UI.
 *
 * Hooks `woocommerce_checkout_order_processed` (fires when the order is created,
 * regardless of whether the buyer returns to the thank-you page) so off-site
 * gateways are covered. The Attributor enforces one attribution per order, so a
 * re-fire never credits a second flow even if last-touch changed in between.
 */
final class AttributionTrigger {

	/** Default attribution window: 7 days. */
	public const DEFAULT_WINDOW = 604800;

	public function __construct( private readonly Attributor $attributor ) {}

	public function register(): void {
		\add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order' ), 20, 1 );
	}

	public static function window(): int {
		$days = ( new \CartQuill\Settings\OptionsSettings() )->attribution_window_days();
		/**
		 * Override the attribution window (seconds). Defaults to the days set in
		 * CartQuill Settings.
		 *
		 * @param int $window Window in seconds.
		 */
		return (int) \apply_filters( 'cartquill_attribution_window', $days * DAY_IN_SECONDS );
	}

	/**
	 * @param int $order_id The order that was placed.
	 */
	public function on_order( $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = (string) $order->get_billing_email();
		if ( '' === $email ) {
			return;
		}

		$created = $order->get_date_created();
		$ts      = $created ? (int) $created->getTimestamp() : time();

		$this->attributor->attribute(
			$email,
			(int) $order_id,
			(float) $order->get_total(),
			$ts,
			self::window()
		);
	}
}
