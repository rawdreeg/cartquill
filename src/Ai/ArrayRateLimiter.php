<?php
/**
 * In-memory RateLimiter for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class ArrayRateLimiter implements RateLimiter {

	private int $used = 0;

	public function __construct( private readonly int $limit ) {}

	public function try_consume(): bool {
		if ( $this->used >= $this->limit ) {
			return false;
		}
		++$this->used;
		return true;
	}

	public function remaining(): int {
		return max( 0, $this->limit - $this->used );
	}

	public function reset_at(): int {
		return $this->used > 0 ? 1 : 0;
	}
}
