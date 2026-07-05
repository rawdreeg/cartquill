<?php
/**
 * Builds the per-flow reporting rows from raw stats.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Reporting;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Persistence\FlowRecord;

/**
 * Pure aggregation: given the store's flows plus per-flow engagement stats and
 * attributed revenue, produce one row per flow (zeros where a flow has no
 * activity yet). Kept free of persistence so it is tested directly.
 */
final class FlowReport {

	/**
	 * @param list<FlowRecord>                                                     $flows
	 * @param array<int, array{sent: int, opened: int, clicked: int}>               $stats    By flow id.
	 * @param array<int, float>                                                     $revenue  By flow id.
	 * @param array<int, array{delivered: int, bounced: int, complained: int}>      $delivery By flow id (Deliverability add-on; empty on the free tier).
	 *
	 * @return list<FlowReportRow>
	 */
	public function build( array $flows, array $stats, array $revenue, array $delivery = array() ): array {
		$rows = array();
		foreach ( $flows as $flow ) {
			$id       = (int) $flow->id;
			$counts   = $stats[ $id ] ?? array( 'sent' => 0, 'opened' => 0, 'clicked' => 0 );
			$deliv    = $delivery[ $id ] ?? array( 'delivered' => 0, 'bounced' => 0, 'complained' => 0 );
			$rows[]   = new FlowReportRow(
				flow_id: $id,
				name: $flow->name,
				sent: (int) $counts['sent'],
				opened: (int) $counts['opened'],
				clicked: (int) $counts['clicked'],
				revenue: (float) ( $revenue[ $id ] ?? 0.0 ),
				delivered: (int) $deliv['delivered'],
				bounced: (int) $deliv['bounced'],
				complained: (int) $deliv['complained'],
			);
		}
		return $rows;
	}

	/**
	 * Total attributed revenue across rows.
	 *
	 * @param list<FlowReportRow> $rows
	 */
	public function total_revenue( array $rows ): float {
		return array_reduce( $rows, static fn( float $c, FlowReportRow $r ) => $c + $r->revenue, 0.0 );
	}
}
