<?php
/**
 * A no-op meter: unlimited, records nothing.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Metering;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The default when metering is not wired (tests, or the engine constructed
 * without a meter): never caps, never counts.
 */
final class NullMeter implements Meter {

	public function current(): int {
		return 0;
	}

	public function limit(): int {
		return PHP_INT_MAX;
	}

	public function remaining(): int {
		return PHP_INT_MAX;
	}

	public function would_exceed(): bool {
		return false;
	}

	public function increment(): void {
	}
}
