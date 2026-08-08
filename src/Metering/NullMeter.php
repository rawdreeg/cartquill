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
 * The meter CartQuill runs with: it never defers a step and never counts one, so
 * the engine executes every step as soon as it is due. This is the default the
 * engine falls back to when no meter is injected, and what the plugin wires in
 * {@see \CartQuill\Plugin}.
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
