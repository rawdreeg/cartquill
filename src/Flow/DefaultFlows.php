<?php
/**
 * Built-in flow definitions shipped with the free core.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Persistence\FlowRecord;

/**
 * Production definitions for the core flows. The flow-library slice adds the
 * install/activate UI on top of these; triggers and the scanner enroll into
 * whichever of them a store has activated.
 *
 * Kept here (not in tests) so the default step timing and copy are real code.
 */
final class DefaultFlows {

	public const TYPE_ABANDONED_CART = 'abandoned_cart';
	public const TYPE_WELCOME        = 'welcome';
	public const TYPE_POST_PURCHASE  = 'post_purchase';
	public const TYPE_WIN_BACK       = 'win_back';

	/**
	 * The default abandoned-cart flow: a nudge at t+1h and a follow-up at t+24h,
	 * each of which exits the moment the customer places an order.
	 *
	 * @param string $status Initial status (defaults to draft — a store activates it).
	 */
	public static function abandoned_cart( string $status = FlowRecord::STATUS_DRAFT ): FlowRecord {
		$exit = array( array( 'type' => 'exit_if_ordered' ) );

		return new FlowRecord(
			id: null,
			name: 'Abandoned cart',
			type: self::TYPE_ABANDONED_CART,
			status: $status,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep(
					delay: 3600, // t+1h
					subject: 'You left something behind',
					body: '<p>Hi — you left items in your cart at {{ store_name }}. Complete your order any time.</p>',
					conditions: $exit,
				),
				new FlowStep(
					delay: 86400, // t+24h
					subject: 'Still thinking it over?',
					body: '<p>Your cart at {{ store_name }} is still waiting. Here to help if you have questions.</p>',
					conditions: $exit,
				),
			),
		);
	}

	/**
	 * The default welcome flow: an immediate hello and a t+3d follow-up.
	 *
	 * @param string $status Initial status (defaults to draft).
	 */
	public static function welcome( string $status = FlowRecord::STATUS_DRAFT ): FlowRecord {
		return new FlowRecord(
			id: null,
			name: 'Welcome',
			type: self::TYPE_WELCOME,
			status: $status,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep(
					delay: 0, // immediate
					subject: 'Welcome to {{ store_name }}',
					body: '<p>Thanks for joining {{ store_name }}! We are glad you are here.</p>',
				),
				new FlowStep(
					delay: 259200, // t+3d
					subject: 'Getting the most out of {{ store_name }}',
					body: '<p>A few favourites and tips to help you get started.</p>',
				),
			),
		);
	}

	/**
	 * The default post-purchase flow: an immediate thank-you and a t+14d cross-sell.
	 *
	 * @param string $status Initial status (defaults to draft).
	 */
	public static function post_purchase( string $status = FlowRecord::STATUS_DRAFT ): FlowRecord {
		return new FlowRecord(
			id: null,
			name: 'Post-purchase',
			type: self::TYPE_POST_PURCHASE,
			status: $status,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep(
					delay: 0, // t+0
					subject: 'Thanks for your order',
					body: '<p>Thanks for shopping with {{ store_name }}. Your order is on its way.</p>',
				),
				new FlowStep(
					delay: 1209600, // t+14d
					subject: 'You might also like…',
					body: '<p>Based on your order, here are a few things other customers love.</p>',
				),
			),
		);
	}

	/**
	 * The default win-back flow: a re-engagement nudge plus an optional t+7d
	 * follow-up, each of which exits the moment the customer orders again.
	 *
	 * @param string $status Initial status (defaults to draft).
	 */
	public static function win_back( string $status = FlowRecord::STATUS_DRAFT ): FlowRecord {
		$exit = array( array( 'type' => 'exit_if_ordered' ) );

		return new FlowRecord(
			id: null,
			name: 'Win-back',
			type: self::TYPE_WIN_BACK,
			status: $status,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep(
					delay: 0,
					subject: 'We miss you at {{ store_name }}',
					body: '<p>It has been a while! Here is a little nudge to come back to {{ store_name }}.</p>',
					conditions: $exit,
				),
				new FlowStep(
					delay: 604800, // t+7d follow-up
					subject: 'One more reason to come back',
					body: '<p>Still here whenever you are ready. Anything we can help with?</p>',
					conditions: $exit,
				),
			),
		);
	}
}
