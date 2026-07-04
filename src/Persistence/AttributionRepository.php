<?php
/**
 * Persistence seam for the `attributions` table.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

interface AttributionRepository {

	/**
	 * Persist an attribution, idempotently on (order_id, flow_id).
	 *
	 * Returns the stored record, or null if this (order, flow) was already
	 * attributed — so an order is never double-counted.
	 */
	public function record( AttributionRecord $record ): ?AttributionRecord;

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
