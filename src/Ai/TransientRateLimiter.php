<?php
/**
 * Transient-backed RateLimiter (runtime): N generations per rolling window.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Support\Clock;
use FlowForge\Support\SystemClock;

/**
 * A fixed-window counter kept in a transient: the window is anchored on first
 * use (its reset timestamp is stored, so it never slides forward as more is
 * consumed) and the count resets when the window elapses.
 *
 * This is a best-effort SOFT throttle. It is not perfectly atomic (a rare
 * concurrent burst can overshoot by a few) and a non-persistent object cache
 * could drop the transient — the vendor proxy is the authoritative limit; this
 * only keeps ordinary usage (and cost) predictable and gives the UI an
 * allowance to show.
 */
final class TransientRateLimiter implements RateLimiter {

	private const KEY = 'flowforge_ai_rate';

	private readonly Clock $clock;

	public function __construct(
		private readonly int $limit,
		private readonly int $window_seconds,
		?Clock $clock = null,
	) {
		$this->clock = $clock ?? new SystemClock();
	}

	public function try_consume(): bool {
		$state = $this->window();
		if ( $state['used'] >= $this->limit ) {
			return false;
		}

		$state['used']++;
		$this->store( $state );
		return true;
	}

	public function remaining(): int {
		return max( 0, $this->limit - $this->window()['used'] );
	}

	public function reset_at(): int {
		$state = $this->window();
		return $state['used'] > 0 ? $state['reset'] : 0;
	}

	/**
	 * The current fixed window, starting a fresh one when none is open or the
	 * previous has elapsed.
	 *
	 * @return array{reset: int, used: int}
	 */
	private function window(): array {
		$now   = $this->clock->now();
		$state = \get_transient( self::KEY );
		if ( ! is_array( $state ) || ! isset( $state['reset'], $state['used'] ) || $now >= (int) $state['reset'] ) {
			return array( 'reset' => $now + $this->window_seconds, 'used' => 0 );
		}
		return array( 'reset' => (int) $state['reset'], 'used' => (int) $state['used'] );
	}

	/**
	 * @param array{reset: int, used: int} $state
	 */
	private function store( array $state ): void {
		// TTL tracks the remaining window so the anchor stays fixed, not sliding.
		$ttl = max( 1, $state['reset'] - $this->clock->now() );
		\set_transient( self::KEY, $state, $ttl );
	}
}
