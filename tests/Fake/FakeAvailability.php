<?php
/**
 * An {@see Availability} that offers exactly what it is told to.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Fake;

use CartQuill\Builder\Availability;

/**
 * The catalog's availability seam exists so an extension can say when its own
 * contributed descriptors are ready. This double stands in for such an
 * extension, holding a flat list of capabilities it grants and a flag for the
 * data-driven gates.
 *
 * Tests drive the seam through this rather than through any real consumer, so
 * they assert what the catalog promises anyone who implements the interface —
 * not the behaviour of one particular implementation.
 */
final class FakeAvailability implements Availability {

	/**
	 * @param list<string> $capabilities Capabilities this extension grants.
	 * @param bool         $gates        Whether the data-driven condition gates are offered.
	 */
	public function __construct(
		private readonly array $capabilities = array(),
		private readonly bool $gates = false
	) {}

	public function allows( ?string $capability ): bool {
		// A descriptor with no requirement is core, and core is always offered.
		return null === $capability || in_array( $capability, $this->capabilities, true );
	}

	public function allows_gates(): bool {
		return $this->gates;
	}
}
