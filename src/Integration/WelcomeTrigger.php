<?php
/**
 * Welcome trigger: enrolls a customer on their first order or newsletter signup.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Engine\CustomerActivity;
use FlowForge\Engine\FlowTypeEnroller;
use FlowForge\Flow\DefaultFlows;

/**
 * Starts the welcome flow (immediate hello + t+3d follow-up) when a relationship
 * begins: a customer's first order, or a newsletter signup.
 *
 * Order enrollment hooks `woocommerce_checkout_order_processed` (fires when the
 * order is created) rather than `woocommerce_thankyou` (fires only when the buyer
 * loads the order-received page), so off-site/redirect gateways, admin-created,
 * and API-created orders are covered. First-order detection uses the order count
 * — a returning customer whose count is greater than one is not re-welcomed —
 * and enrollment is idempotent, so a hook re-fire never double-enrolls. Newsletter
 * signups enroll via a public method other code (or a `flowforge_newsletter_signup`
 * action) can call.
 */
final class WelcomeTrigger {

	public const SIGNUP_HOOK = 'flowforge_newsletter_signup';

	public function __construct(
		private readonly FlowTypeEnroller $enroller,
		private readonly CustomerActivity $activity,
	) {}

	public function register(): void {
		\add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order' ), 10, 1 );
		\add_action( self::SIGNUP_HOOK, array( $this, 'on_newsletter_signup' ), 10, 1 );
	}

	/**
	 * @param int $order_id The order that was just created.
	 */
	public function on_order( $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email || ! \is_email( $email ) ) {
			return;
		}

		// Only welcome first-time customers.
		if ( $this->activity->order_count( (string) $email ) > 1 ) {
			return;
		}

		$this->enroller->enroll( DefaultFlows::TYPE_WELCOME, (string) $email, 'first_order' );
	}

	/**
	 * @param string $email The address that signed up.
	 */
	public function on_newsletter_signup( $email ): void {
		if ( ! $email || ! \is_email( $email ) ) {
			return;
		}
		$this->enroller->enroll( DefaultFlows::TYPE_WELCOME, (string) $email, 'newsletter' );
	}
}
