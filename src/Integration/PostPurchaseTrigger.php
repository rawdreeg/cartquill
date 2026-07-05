<?php
/**
 * Post-purchase trigger: enrolls the buyer when an order completes.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Engine\FlowTypeEnroller;
use CartQuill\Flow\DefaultFlows;

/**
 * On order completion, enrolls the buyer into every active post-purchase flow
 * (default steps: an immediate thank-you and a t+14d cross-sell). Enrollment is
 * idempotent, so re-completing an order does not enroll the buyer twice.
 */
final class PostPurchaseTrigger {

	public function __construct( private readonly FlowTypeEnroller $enroller ) {}

	public function register(): void {
		\add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 10, 1 );
	}

	/**
	 * @param int $order_id The order that reached the completed status.
	 */
	public function on_order_completed( $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email || ! \is_email( $email ) ) {
			return;
		}

		$this->enroller->enroll( DefaultFlows::TYPE_POST_PURCHASE, (string) $email, 'post_purchase' );
	}
}
