<?php
/**
 * Licensing gate the add-ons check before registering their capabilities.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Answers "is this capability licensed?" — the single seam add-ons (AI,
 * Deliverability) consult before wiring themselves in. The concrete
 * implementation is manual/option-backed in the scaffold and Freemius-backed in
 * production; the gate contract is the same either way.
 */
interface License {

	/**
	 * Whether the given capability (a Plans::* constant) is active. A Pro
	 * license activates every add-on capability.
	 */
	public function is_active( string $capability ): bool;

	/**
	 * The held subscription tier (a Plans::TIERS value), or '' when none is held.
	 * A display/branching accessor; the *enforceable* numbers live in limits(),
	 * which is the single seam the meter and plan gate read so a filter override
	 * reaches every check.
	 */
	public function plan(): string;

	/**
	 * The held plan's numeric limits, keyed by resource: `actions` (the monthly
	 * action cap the meter enforces), `workflows` (the active-flow cap; 0 =
	 * unlimited), and `conditional_logic` (0/1). Values are the tier's
	 * entitlements, overridable via the `cartquill_plan_limits` filter.
	 *
	 * @return array<string, int>
	 */
	public function limits(): array;
}
