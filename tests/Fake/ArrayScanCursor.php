<?php
/**
 * In-memory ScanCursor for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Fake;

use FlowForge\Support\ScanCursor;

/**
 * Holds the scan offset in memory and records whether it was cleared, so tests
 * can assert both the parked value and the drained-vs-resumed branch.
 */
final class ArrayScanCursor implements ScanCursor {

	private int $offset;
	private bool $cleared = false;

	public function __construct( int $initial = 0 ) {
		$this->offset = max( 0, $initial );
	}

	public function get(): int {
		return $this->offset;
	}

	public function save( int $offset ): void {
		$this->offset  = max( 0, $offset );
		$this->cleared = false;
	}

	public function clear(): void {
		$this->offset  = 0;
		$this->cleared = true;
	}

	public function was_cleared(): bool {
		return $this->cleared;
	}
}
