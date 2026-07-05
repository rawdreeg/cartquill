<?php
/**
 * Persistence seam for the `attributions` table.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

interface AttributionRepository {

	/**
	 * Persist an attribution, idempotently on order_id (one row per order).
	 *
	 * Returns the stored record, or null if this order was already attributed —
	 * so an order's revenue is never counted for more than one flow.
	 */
	public function record( AttributionRecord $record ): ?AttributionRecord;

	/**
	 * The existing attribution for an order, or null if none.
	 */
	public function find_by_order( int $order_id ): ?AttributionRecord;

	/**
	 * Attributions whose message_id is in the given set (GDPR export).
	 *
	 * @param list<int> $message_ids
	 * @return list<AttributionRecord>
	 */
	public function for_messages( array $message_ids ): array;

	/**
	 * Null the message_id of attributions in the given set, keeping the revenue
	 * record but severing its personal link (GDPR erase).
	 *
	 * @param list<int> $message_ids
	 * @return int Number of rows anonymized.
	 */
	public function anonymize_messages( array $message_ids ): int;

	/**
	 * Total attributed revenue per flow id.
	 *
	 * @return array<int, float>
	 */
	public function revenue_by_flow(): array;

	/**
	 * @return list<AttributionRecord>
	 */
	public function all(): array;
}
