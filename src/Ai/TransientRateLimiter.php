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

/**
 * Stores the used count in a WordPress transient that expires at the end of the
 * window, so the allowance resets automatically.
 */
final class TransientRateLimiter implements RateLimiter {

	private const KEY = 'flowforge_ai_rate';

	public function __construct(
		private readonly int $limit,
		private readonly int $window_seconds,
	) {}

	public function try_consume(): bool {
		$used = (int) \get_transient( self::KEY );
		if ( $used >= $this->limit ) {
			return false;
		}
		\set_transient( self::KEY, $used + 1, $this->window_seconds );
		return true;
	}

	public function remaining(): int {
		return max( 0, $this->limit - (int) \get_transient( self::KEY ) );
	}
}
