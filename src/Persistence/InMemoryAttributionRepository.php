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

	/** @var array<int, true> seen order ids */
	private array $unique = array();

	private int $next_id = 1;

	public function record( AttributionRecord $record ): ?AttributionRecord {
		if ( isset( $this->unique[ $record->order_id ] ) ) {
			return null;
		}
		$this->unique[ $record->order_id ] = true;

		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function find_by_order( int $order_id ): ?AttributionRecord {
		foreach ( $this->records as $record ) {
			if ( $record->order_id === $order_id ) {
				return $record;
			}
		}
		return null;
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
