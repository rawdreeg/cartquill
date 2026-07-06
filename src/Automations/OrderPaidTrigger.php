<?php
/**
 * Order-paid trigger: enrolls the buyer into order-alert flows when paid.
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
 * On payment, enrolls the buyer into every active order-alert flow, capturing
 * the order total into the enrollment context for value-based conditions.
 * Hooks both `woocommerce_payment_complete` and the processing-status
 * transition to cover the ways stores mark an order paid; enrollment is
 * idempotent, so the overlap never double-enrolls.
 */
final class OrderPaidTrigger {

	public const TYPE = 'order_alert';

	public function __construct( private readonly FlowTypeEnroller $enroller ) {}

	public function register(): void {
		\add_action( 'woocommerce_payment_complete', array( $this, 'on_order_paid' ), 10, 1 );
		\add_action( 'woocommerce_order_status_processing', array( $this, 'on_order_paid' ), 10, 1 );
	}

	/**
	 * @param int $order_id The order that was paid.
	 */
	public function on_order_paid( $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email || ! \is_email( $email ) ) {
			return;
		}

		$context = array(
			'order_id'    => (int) $order_id,
			'order_total' => (float) $order->get_total(),
		);

		$this->enroller->enroll( self::TYPE, (string) $email, 'order_paid', $context );
	}
}
