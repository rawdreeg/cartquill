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
}
