<?php
/**
 * Whether the builder may offer a given descriptor.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The seam {@see BuilderCatalog} consults when it stamps each catalog entry as
 * offerable or not. The plugin ships {@see OpenAvailability}, which offers
 * everything; the interface exists so an extension that contributes its own
 * triggers and actions can also say when those extras are ready to use (for
 * example, an integration that still needs its account connected).
 *
 * Core never restricts a core descriptor — the answer for anything the plugin
 * itself ships is always yes.
 */
interface Availability {

	/**
	 * Whether a descriptor requiring $capability may be offered. A descriptor with
	 * no capability requirement (null) is core and always offerable.
	 */
	public function allows( ?string $capability ): bool;

	/**
	 * Whether the data-driven condition gates may be offered.
	 */
	public function allows_gates(): bool;
}
