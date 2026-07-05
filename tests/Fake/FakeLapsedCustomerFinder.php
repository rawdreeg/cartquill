<?php
/**
 * Configurable LapsedCustomerFinder for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Fake;

use FlowForge\Engine\LapsedBatch;
use FlowForge\Engine\LapsedCustomerFinder;

/**
 * Seeded with each customer's most recent order time; reports those whose
 * latest order is strictly before the cutoff — mirroring the real finder's
 * contract so scanner tests exercise the same recency semantics. Paging is
 * honoured over a stable (sorted) email order so cursor behaviour is testable.
 */
final class FakeLapsedCustomerFinder implements LapsedCustomerFinder {

	/** @var array<string, int> email => latest order timestamp */
	private array $latest = array();

	public function set_last_order( string $email, int $at ): void {
		$this->latest[ strtolower( $email ) ] = $at;
	}

	public function lapsed_before( int $cutoff, int $limit = 0, int $offset = 0 ): LapsedBatch {
		$lapsed = array();
		foreach ( $this->latest as $email => $at ) {
			if ( $at < $cutoff ) {
				$lapsed[] = $email;
			}
		}
		sort( $lapsed );

		$offset = max( 0, $offset );
		$page   = $limit > 0 ? array_slice( $lapsed, $offset, $limit ) : array_slice( $lapsed, $offset );
		$next   = $offset + count( $page );
		$done   = $limit <= 0 || $next >= count( $lapsed );

		return new LapsedBatch( array_values( $page ), $next, $done );
	}
}
