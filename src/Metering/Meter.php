<?php
/**
 * The usage meter the engine consults before/after executing an action.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Metering;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Answers "how much of this month's action cap is used?" and records executions.
 * The engine checks {@see self::would_exceed()} before an action and calls
 * {@see self::increment()} after a successful one, so exactly `limit` actions
 * run per month and over-cap steps defer rather than being dropped.
 */
interface Meter {

	/** Actions executed in the current period. */
	public function current(): int;

	/** The monthly action cap. */
	public function limit(): int;

	/** Actions still available this period (never negative). */
	public function remaining(): int;

	/** Whether executing one more action now would exceed the cap. */
	public function would_exceed(): bool;

	/** Record one executed action against the current period. */
	public function increment(): void;
}
