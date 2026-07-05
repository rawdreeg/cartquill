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
