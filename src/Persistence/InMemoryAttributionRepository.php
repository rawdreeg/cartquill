<?php
/**
 * In-memory AttributionRepository for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class InMemoryAttributionRepository implements AttributionRepository {

	/** @var array<int, AttributionRecord> */
	private array $records = array();

	/** @var array<string, true> seen (order:flow) keys */
	private array $unique = array();

	private int $next_id = 1;

	public function record( AttributionRecord $record ): ?AttributionRecord {
		$key = $record->order_id . ':' . $record->flow_id;
		if ( isset( $this->unique[ $key ] ) ) {
			return null;
		}
		$this->unique[ $key ] = true;

		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function revenue_by_flow(): array {
		$totals = array();
		foreach ( $this->records as $record ) {
			$totals[ $record->flow_id ] = ( $totals[ $record->flow_id ] ?? 0.0 ) + $record->revenue;
		}
		return $totals;
	}

	public function all(): array {
		return array_values( $this->records );
	}
}
