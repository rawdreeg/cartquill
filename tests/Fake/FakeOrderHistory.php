<?php
/**
 * Configurable OrderHistory for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Fake;

use FlowForge\Engine\OrderHistory;

final class FakeOrderHistory implements OrderHistory {

	/** @var array<string, int> email => latest order timestamp */
	private array $latest = array();

	/**
	 * Record a customer's most recent order time.
	 */
	public function set_last_order( string $email, int $at ): void {
		$this->latest[ strtolower( $email ) ] = $at;
	}

	public function customer_emails(): array {
		return array_keys( $this->latest );
	}

	public function last_order_at( string $email ): ?int {
		return $this->latest[ strtolower( $email ) ] ?? null;
	}
}
