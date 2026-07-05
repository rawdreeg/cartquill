<?php
/**
 * A row in the `attributions` table: revenue credited to a flow.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Mirrors the `attributions` table. One row per (order, flow) — last-touch, so
 * an order credits at most the single most-recent flow message within the
 * attribution window.
 */
final class AttributionRecord {

	public function __construct(
		public readonly ?int $id,
		public readonly int $order_id,
		public readonly int $flow_id,
		public readonly ?int $message_id,
		public readonly float $revenue,
		public readonly ?string $attributed_at = null,
	) {}

	public function with_id( int $id ): self {
		return new self( $id, $this->order_id, $this->flow_id, $this->message_id, $this->revenue, $this->attributed_at );
	}
}
