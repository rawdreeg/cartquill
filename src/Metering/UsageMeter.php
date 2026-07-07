<?php
/**
 * The usage meter: counts executed actions against the plan's monthly cap.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Metering;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Licensing\License;
use CartQuill\Support\Clock;

/**
 * Reads usage from the {@see UsageStore} for the current month and the cap from
 * the {@see License} limits seam. An unset/zero cap is treated as unlimited (so
 * an unconfigured store is never bricked); a positive cap is enforced exactly.
 */
final class UsageMeter implements Meter {

	public function __construct(
		private readonly UsageStore $store,
		private readonly License $license,
		private readonly Clock $clock,
	) {}

	public function current(): int {
		return $this->store->count( $this->period() );
	}

	public function limit(): int {
		$actions = (int) ( $this->license->limits()['actions'] ?? 0 );
		return $actions > 0 ? $actions : PHP_INT_MAX;
	}

	public function remaining(): int {
		return max( 0, $this->limit() - $this->current() );
	}

	public function would_exceed(): bool {
		return $this->current() >= $this->limit();
	}

	public function increment(): void {
		$this->store->increment( $this->period() );
	}

	/**
	 * The current billing period (UTC month, e.g. "2026-07").
	 */
	private function period(): string {
		return gmdate( 'Y-m', $this->clock->now() );
	}
}
