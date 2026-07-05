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
}
