<?php
/**
 * Configurable LapsedCustomerFinder for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Fake;

use FlowForge\Engine\LapsedCustomerFinder;

/**
 * Seeded with each customer's most recent order time; reports those whose
 * latest order is strictly before the cutoff — mirroring the real finder's
 * contract so scanner tests exercise the same recency semantics.
 */
final class FakeLapsedCustomerFinder implements LapsedCustomerFinder {

	/** @var array<string, int> email => latest order timestamp */
	private array $latest = array();

	public function set_last_order( string $email, int $at ): void {
		$this->latest[ strtolower( $email ) ] = $at;
	}

	public function lapsed_before( int $cutoff ): array {
		$lapsed = array();
		foreach ( $this->latest as $email => $at ) {
			if ( $at < $cutoff ) {
				$lapsed[] = $email;
			}
		}
		return $lapsed;
	}
}
