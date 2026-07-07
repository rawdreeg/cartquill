<?php
/**
 * In-memory UsageStore for tests.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Metering;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class InMemoryUsageStore implements UsageStore {

	/** @var array<string, int> period => count */
	private array $counts = array();

	public function count( string $period ): int {
		return $this->counts[ $period ] ?? 0;
	}

	public function increment( string $period ): void {
		$this->counts[ $period ] = ( $this->counts[ $period ] ?? 0 ) + 1;
	}
}
