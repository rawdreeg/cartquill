<?php
/**
 * Configurable CustomerActivity for tests.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Fake;

use CartQuill\Engine\CustomerActivity;

final class FakeCustomerActivity implements CustomerActivity {

	/** @var array<string, list<int>> email => order timestamps */
	private array $orders = array();

	/**
	 * Record that a customer placed an order at a given time.
	 */
	public function record_order( string $email, int $at ): void {
		$this->orders[ strtolower( $email ) ][] = $at;
	}

	public function has_ordered_since( string $email, int $since ): bool {
		foreach ( $this->orders[ strtolower( $email ) ] ?? array() as $at ) {
			if ( $at >= $since ) {
				return true;
			}
		}
		return false;
	}

	public function order_count( string $email ): int {
		return count( $this->orders[ strtolower( $email ) ] ?? array() );
	}
}

