<?php
/**
 * Order-placed trigger: enrolls the buyer into active order-driven flows.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Integration;

use FlowForge\Engine\Enroller;
use FlowForge\Persistence\FlowRepository;

/**
 * On a completed order, enrolls the buyer into every active flow of the
 * order-driven type. The engine (Enroller + StepRunner) does the rest —
 * scheduling, delays, conditions, sending. Enrollment idempotency lives in the
 * Enroller, so WooCommerce firing `woocommerce_thankyou` twice does not enroll
 * the buyer twice.
 *
 * The post-purchase slice formalizes the default steps and adds the welcome /
 * first-order triggers; here the wiring proves the engine runs from a real hook.
 */
final class WooOrderTrigger {

	private const FLOW_TYPE = 'post_purchase';

	public function __construct(
		private readonly Enroller $enroller,
		private readonly FlowRepository $flows,
	) {}

	public function register(): void {
		\add_action( 'woocommerce_thankyou', array( $this, 'on_order_received' ), 10, 1 );
	}

	/**
	 * @param int $order_id The order that was just placed.
	 */
	public function on_order_received( $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email || ! \is_email( $email ) ) {
			return;
		}

		foreach ( $this->flows->active_by_type( self::FLOW_TYPE ) as $flow ) {
			$this->enroller->enroll( $flow, (string) $email );
		}
	}
}
