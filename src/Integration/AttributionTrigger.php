<?php
/**
 * Attributes an order's revenue to a flow when the order is placed.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Integration;

use FlowForge\Attribution\Attributor;

/**
 * On order placement, hands the order to the Attributor. The attribution window
 * is filterable and surfaced in the reporting UI.
 *
 * Hooks `woocommerce_checkout_order_processed` (fires when the order is created,
 * regardless of whether the buyer returns to the thank-you page) so off-site
 * gateways are covered. The unique (order, flow) key makes re-fires harmless.
 */
final class AttributionTrigger {

	/** Default attribution window: 7 days. */
	public const DEFAULT_WINDOW = 604800;

	public function __construct( private readonly Attributor $attributor ) {}

	public function register(): void {
		\add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order' ), 20, 1 );
	}

	public static function window(): int {
		return (int) \apply_filters( 'flowforge_attribution_window', self::DEFAULT_WINDOW );
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
