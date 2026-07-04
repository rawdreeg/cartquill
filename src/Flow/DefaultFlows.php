<?php
/**
 * Built-in flow definitions shipped with the free core.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Flow;

use FlowForge\Persistence\FlowRecord;

/**
 * Production definitions for the core flows. The flow-library slice adds the
 * install/activate UI on top of these; triggers and the scanner enroll into
 * whichever of them a store has activated.
 *
 * Kept here (not in tests) so the default step timing and copy are real code.
 */
final class DefaultFlows {

	public const TYPE_ABANDONED_CART = 'abandoned_cart';

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
}
