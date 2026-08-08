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
 * The email lifecycle triggers CartQuill ships, all always offered. An extension
 * adds its own through the {@see BuilderCatalog::FILTER_TRIGGERS} filter, so its
 * trigger metadata lives with the code that implements it.
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
