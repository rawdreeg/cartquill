<?php
/**
 * Usage rate limiting for AI generation.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Keeps AI usage (and cost) predictable. `try_consume()` atomically checks the
 * remaining allowance and, if any remains, consumes one — returning whether the
 * caller may proceed.
 */
interface RateLimiter {

	public function try_consume(): bool;

	/**
	 * Remaining allowance in the current window.
	 */
	public function remaining(): int;

	/**
	 * Unix timestamp when the current window resets, or 0 if none is open.
	 */
	public function reset_at(): int;
}
