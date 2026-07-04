<?php
/**
 * Tracer-bullet trigger: a completed order fires the spine flow.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Integration;

use FlowForge\Engine\SpineDispatcher;
use FlowForge\Flow\FlowDefinition;
use FlowForge\Flow\FlowStep;
use FlowForge\Persistence\EnrollmentRecord;
use FlowForge\Persistence\EnrollmentRepository;

/**
 * Wires a WooCommerce hook to the spine dispatcher to prove the trigger ->
 * enroll -> send -> record path end-to-end.
 *
 * This is deliberately a single hardcoded, single-step flow. The engine slice
 * replaces it with data-driven flows, real enrollment, delays and conditions.
 */
final class WooOrderTrigger {

	public function __construct(
		private readonly SpineDispatcher $dispatcher,
		private readonly EnrollmentRepository $enrollments,
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

		// Guard against WooCommerce firing woocommerce_thankyou more than once
		// for the same order on page refresh.
		if ( $order->get_meta( '_flowforge_spine_sent' ) ) {
			return;
		}

		$flow = $this->spine_flow();

		$enrollment = $this->enrollments->save(
			new EnrollmentRecord(
				id: null,
				flow_id: $flow->id,
				customer_email: (string) $email,
				status: EnrollmentRecord::STATUS_ACTIVE,
			)
		);

		$this->dispatcher->dispatch( $flow, 0, (string) $email, $enrollment->id );

		$order->update_meta_data( '_flowforge_spine_sent', '1' );
		$order->save();
	}

	private function spine_flow(): FlowDefinition {
		return new FlowDefinition(
			id: 0,
			name: 'Spine — order received',
			type: 'spine',
			steps: array(
				new FlowStep(
					delay: 0,
					subject: 'Thanks for your order',
					body: '<p>Thanks for shopping with {{ store_name }}. This is FlowForge saying hello.</p>',
				),
			),
		);
	}
}
