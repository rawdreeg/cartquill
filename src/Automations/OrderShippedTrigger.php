<?php
/**
 * Order-shipped trigger: texts a tracking update when an order ships.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Engine\FlowTypeEnroller;

/**
 * When an order reaches the shipped/completed state, enrols the buyer into every
 * active shipping-update flow, capturing their phone and a tracking URL into the
 * enrollment context (so a has_phone gate and the SMS body can use them). The
 * "shipped" status is the completed status by default; stores using a custom
 * shipped status can add it via the `cartquill_order_shipped_statuses` filter.
 * Enrollment is idempotent.
 */
final class OrderShippedTrigger {

	public const TYPE = 'shipping_update';

	public function __construct( private readonly FlowTypeEnroller $enroller ) {}

	public function register(): void {
		foreach ( $this->shipped_statuses() as $status ) {
			\add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_order_shipped' ), 10, 1 );
		}
	}

	/**
	 * @param int $order_id The order that shipped.
	 */
	public function on_order_shipped( $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email || ! \is_email( $email ) ) {
			return;
		}

		$context = array(
			'phone'        => Phone::normalize( (string) $order->get_billing_phone() ),
			'tracking_url' => (string) \apply_filters( 'cartquill_order_tracking_url', (string) $order->get_view_order_url(), (int) $order_id ),
		);

		$this->enroller->enroll( self::TYPE, (string) $email, 'order_shipped', $context );
	}

	/**
	 * @return list<string>
	 */
	private function shipped_statuses(): array {
		$statuses = \apply_filters( 'cartquill_order_shipped_statuses', array( 'completed' ) );
		return array_values( array_filter( array_map( 'strval', (array) $statuses ) ) );
	}
}
