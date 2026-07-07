<?php
/**
 * Account-created trigger: enrolls a new customer into the welcome recipe.
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
 * When a WooCommerce customer account is created, enrolls them into every active
 * welcome recipe, capturing their marketing opt-in into the enrollment context
 * (so the recipe's marketing_opt_in gate can decide whether to sync them) and
 * recording the consent source. Enrollment is idempotent.
 */
final class AccountCreatedTrigger {

	public const TYPE = 'account_welcome';

	public function __construct( private readonly FlowTypeEnroller $enroller ) {}

	public function register(): void {
		\add_action( 'woocommerce_created_customer', array( $this, 'on_customer_created' ), 10, 1 );
	}

	/**
	 * @param int $customer_id The new customer's user id.
	 */
	public function on_customer_created( $customer_id ): void {
		$user = \get_userdata( (int) $customer_id );
		if ( ! $user || empty( $user->user_email ) || ! \is_email( $user->user_email ) ) {
			return;
		}

		$this->enroller->enroll(
			self::TYPE,
			(string) $user->user_email,
			'account_signup',
			array( 'marketing_opt_in' => $this->marketing_opt_in( (int) $customer_id ) )
		);
	}

	/**
	 * Whether the customer opted in to marketing. Reads a `marketing_opt_in`
	 * checkbox on the registration form (WooCommerce verified that form's nonce
	 * before this hook fires) and lets a theme/marketing plugin override the
	 * source via the `cartquill_marketing_opt_in` filter.
	 */
	private function marketing_opt_in( int $customer_id ): bool {
		$checked = isset( $_POST['marketing_opt_in'] ) && '' !== (string) \wp_unslash( $_POST['marketing_opt_in'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		/**
		 * Filter the captured marketing opt-in for a new customer.
		 *
		 * @param bool $checked     Whether the signup form indicated opt-in.
		 * @param int  $customer_id The new customer's user id.
		 */
		return (bool) \apply_filters( 'cartquill_marketing_opt_in', $checked, $customer_id );
	}
}
