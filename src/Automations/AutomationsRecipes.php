<?php
/**
 * Installable recipes shipped by the multi-tool automation add-on.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Flow\DefaultFlows;
use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\FlowRecord;

/**
 * The add-on's flow templates, contributed to the library via the
 * `cartquill_flow_templates` filter. Kept as real production definitions so the
 * recipe copy and gating are what the engine actually runs.
 */
final class AutomationsRecipes {

	/**
	 * Recipe: when a first-time customer pays, post an alert to Slack **and** log
	 * the sale to Google Sheets — a single trigger fanned across two tools.
	 *
	 * @param string $status Initial status (defaults to draft — a store activates it).
	 */
	public static function order_alert( string $status = FlowRecord::STATUS_DRAFT ): FlowRecord {
		$first_time = array( array( 'type' => 'first_time_customer' ) );

		return new FlowRecord(
			id: null,
			name: 'New order alert',
			type: OrderPaidTrigger::TYPE,
			status: $status,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep(
					delay: 0,
					subject: '',
					body: '',
					conditions: $first_time,
					action: SlackAction::TYPE,
					config: array(
						'channel' => '#orders',
						'text'    => '🎉 New first-time order from {{ customer_email }} (total {{ order_total }})',
					),
				),
				new FlowStep(
					delay: 0,
					subject: '',
					body: '',
					conditions: $first_time,
					action: SheetsAction::TYPE,
					config: array(
						'columns' => array( '{{ order_id }}', '{{ customer_email }}', '{{ order_total }}' ),
					),
				),
			),
		);
	}

	/**
	 * Recipe 4 (Welcome): when a customer creates an account and opted in to
	 * marketing, tag them into the Mailchimp welcome sequence (the tag drives the
	 * Mailchimp journey). A non-opted-in signup skips and completes.
	 *
	 * @param string $status Initial status (defaults to draft).
	 */
	public static function account_welcome( string $status = FlowRecord::STATUS_DRAFT ): FlowRecord {
		return new FlowRecord(
			id: null,
			name: 'Welcome — Mailchimp tag',
			type: AccountCreatedTrigger::TYPE,
			status: $status,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep(
					delay: 0,
					subject: '',
					body: '',
					conditions: array( array( 'type' => 'marketing_opt_in' ) ),
					action: MailchimpAction::TYPE,
					config: array( 'tag' => 'welcome' ),
				),
			),
		);
	}

	/**
	 * Recipe 1 (Cart recovery), completed: the Mailchimp-enhanced abandoned-cart
	 * flow. It replaces the core email-only template when the add-on is active.
	 * A high-value cart (over the threshold) gets a recovery email — sent through
	 * CartQuill's own SenderInterface, never Mailchimp — and every abandoner is
	 * synced to Mailchimp for follow-up. Both steps exit the moment the customer
	 * orders.
	 *
	 * @param string $status Initial status (defaults to draft).
	 */
	public static function cart_recovery( string $status = FlowRecord::STATUS_DRAFT ): FlowRecord {
		$exit = array( 'type' => 'exit_if_ordered' );

		return new FlowRecord(
			id: null,
			name: 'Cart recovery + Mailchimp',
			type: DefaultFlows::TYPE_ABANDONED_CART,
			status: $status,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep(
					delay: 3600, // t+1h
					subject: 'You left something behind',
					body: '<p>Hi — you left items in your cart at {{ store_name }}. Complete your order any time.</p>',
					conditions: array( $exit, array( 'type' => 'cart_value_gt', 'value' => 50 ) ),
				),
				new FlowStep(
					delay: 0,
					subject: '',
					body: '',
					conditions: array( $exit ),
					action: MailchimpAction::TYPE,
					config: array( 'tag' => 'abandoned-cart' ),
				),
			),
		);
	}
}
