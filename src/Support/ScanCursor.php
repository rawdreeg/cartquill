<?php
/**
 * A resumable scan cursor.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Persists a single integer offset between Action Scheduler ticks so a bounded
 * scan can resume where the previous tick stopped. Production stores it in
 * wp_options; tests use an in-memory implementation.
 */
interface ScanCursor {

	/** The parked offset, or 0 to start from the beginning. */
	public function get(): int;

	/** Park the offset to resume from next tick. */
	public function save( int $offset ): void;

	/** Reset the cursor once the scan has drained. */
	public function clear(): void;
}
