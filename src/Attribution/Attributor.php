<?php
/**
 * Last-touch revenue attribution.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Attribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Persistence\AttributionRecord;
use CartQuill\Persistence\AttributionRepository;
use CartQuill\Persistence\MessageRepository;

/**
 * Credits an order's revenue to the single most-recent flow message sent to the
 * buyer within the attribution window. Deliberately last-touch and transparent
 * — no multi-touch claims. Exactly one attribution per order: once an order is
 * attributed, a re-fire never credits a second flow (last-touch can change
 * between hook fires when the original message later bounces).
 */
final class Attributor {

	public function __construct(
		private readonly MessageRepository $messages,
		private readonly AttributionRepository $attributions,
	) {}

	/**
	 * @param string $email          Buyer email.
	 * @param int    $order_id       WooCommerce order id.
	 * @param float  $revenue        Order total to attribute.
	 * @param int    $order_ts       Unix timestamp the order was placed.
	 * @param int    $window_seconds Attribution window (e.g. 7 days).
	 *
	 * @return AttributionRecord|null The recorded attribution, or null if there
	 *                                was no qualifying message (or it was already
	 *                                attributed).
	 */
	public function attribute( string $email, int $order_id, float $revenue, int $order_ts, int $window_seconds ): ?AttributionRecord {
		// One attribution per order: never re-credit an order whose last-touch
		// flow changed between hook fires (e.g. the first flow's message bounced).
		if ( null !== $this->attributions->find_by_order( $order_id ) ) {
			return null;
		}

		$from = gmdate( 'Y-m-d H:i:s', $order_ts - $window_seconds );
		$to   = gmdate( 'Y-m-d H:i:s', $order_ts );

		$message = $this->messages->latest_to_recipient_between( $email, $from, $to );
		if ( null === $message ) {
			return null;
		}

		return $this->attributions->record(
			new AttributionRecord(
				id: null,
				order_id: $order_id,
				flow_id: $message->flow_id,
				message_id: $message->id,
				revenue: $revenue,
				attributed_at: $to,
			)
		);
	}
}
