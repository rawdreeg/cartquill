<?php
/**
 * In-memory License for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Licensing;

final class ArrayLicense implements License {

	/** @var list<string> Held plans (e.g. ['pro'] or ['ai']). */
	private array $plans;

	/**
	 * @param list<string> $plans
	 */
	public function __construct( array $plans = array() ) {
		$this->plans = $plans;
	}

	public function is_active( string $capability ): bool {
		foreach ( $this->plans as $plan ) {
			if ( in_array( $capability, Plans::grants( $plan ), true ) ) {
				return true;
			}
		}
		return false;
	}
}
