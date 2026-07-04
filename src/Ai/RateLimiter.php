<?php
/**
 * Usage rate limiting for AI generation.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

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
}
