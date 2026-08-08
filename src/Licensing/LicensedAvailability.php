<?php
/**
 * Answers the builder catalog's availability questions from the held license.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Builder\Availability;
use CartQuill\Builder\CatalogFactory;

/**
 * The premium edition's {@see Availability}: a descriptor an add-on tagged with a
 * capability is offered once the held plan grants it, and the data-driven gates
 * follow the plan's `conditional_logic` entitlement.
 *
 * Descriptors CartQuill itself ships carry no capability, so they stay offered
 * exactly as they are in the plugin without this bridge installed.
 */
final class LicensedAvailability implements Availability {

	public function __construct( private readonly License $license ) {}

	public function allows( ?string $capability ): bool {
		return null === $capability || $this->license->is_active( $capability );
	}

	public function allows_gates(): bool {
		return 0 !== (int) ( $this->license->limits()['conditional_logic'] ?? 0 );
	}

	/**
	 * Take over the builder catalog's availability seam.
	 */
	public function register(): void {
		\add_filter( CatalogFactory::FILTER_AVAILABILITY, fn(): Availability => $this );
	}
}
