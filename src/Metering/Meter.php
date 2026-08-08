<?php
/**
 * The execution policy the engine consults around each step.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Metering;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * An extension point around step execution. CartQuill itself imposes no limit of
 * any kind: the plugin wires {@see NullMeter}, which always answers "go ahead" and
 * records nothing, so every flow runs as often as its triggers fire.
 *
 * The seam exists so a separately distributed extension that has its own reason to
 * pace work — for instance one that talks to a third-party API with its own rate
 * limits — can defer a step instead of failing it. The engine asks
 * {@see self::would_exceed()} before running a step and calls
 * {@see self::increment()} after a successful one; a step that is deferred stays
 * enrolled and is retried later rather than being dropped.
 */
interface Meter {

	/** Actions executed in the current period. */
	public function current(): int;

	/** The ceiling for the current period; PHP_INT_MAX when there is none. */
	public function limit(): int;

	/** Actions still available this period (never negative). */
	public function remaining(): int;

	/** Whether executing one more action now should be deferred. */
	public function would_exceed(): bool;

	/** Record one executed action against the current period. */
	public function increment(): void;
}
