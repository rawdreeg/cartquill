<?php
/**
 * Frozen time source for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Support;

final class FixedClock implements Clock {

	public function __construct( private int $timestamp ) {}

	public function now(): int {
		return $this->timestamp;
	}

	public function now_mysql(): string {
		return gmdate( 'Y-m-d H:i:s', $this->timestamp );
	}

	/**
	 * Advance the clock by a number of seconds (chainable in tests).
	 */
	public function advance( int $seconds ): self {
		$this->timestamp += $seconds;
		return $this;
	}
}
