<?php
/**
 * The triggers the free core ships — the email lifecycle flows.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The core (email) triggers, offered on every tier. The Automations add-on adds
 * its own triggers (order_alert, account_welcome, shipping_update) through the
 * {@see BuilderCatalog::FILTER_TRIGGERS} filter, so the paid trigger metadata lives
 * with the add-on and never has to be mirrored here.
 */
final class CoreTriggers {

	/**
	 * @return list<TriggerDescriptor>
	 */
	public static function all(): array {
		return array(
			new TriggerDescriptor( 'abandoned_cart', 'Abandoned cart', 'A shopper left items in their cart.', array( 'cart_value' ) ),
			new TriggerDescriptor( 'welcome', 'Newsletter signup', 'A shopper subscribed or created their first order.', array() ),
			new TriggerDescriptor( 'post_purchase', 'Post-purchase', 'An order completed.', array() ),
			new TriggerDescriptor( 'win_back', 'Win-back', 'A past customer has gone quiet.', array() ),
		);
	}
}
