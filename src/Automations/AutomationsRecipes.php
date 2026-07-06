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

use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\FlowRecord;

/**
 * The add-on's flow templates, contributed to the library via the
 * `cartquill_flow_templates` filter. Kept as real production definitions so the
 * recipe copy and gating are what the engine actually runs.
 */
final class AutomationsRecipes {

	/**
	 * Recipe: when a first-time customer pays, post an alert to Slack.
	 *
	 * @param string $status Initial status (defaults to draft — a store activates it).
	 */
	public static function order_alert( string $status = FlowRecord::STATUS_DRAFT ): FlowRecord {
		return new FlowRecord(
			id: null,
			name: 'New order Slack alert',
			type: OrderPaidTrigger::TYPE,
			status: $status,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep(
					delay: 0,
					subject: '',
					body: '',
					conditions: array( array( 'type' => 'first_time_customer' ) ),
					action: SlackAction::TYPE,
					config: array(
						'channel' => '#orders',
						'text'    => '🎉 New first-time order from {{ customer_email }} (total {{ order_total }})',
					),
				),
			),
		);
	}
}
