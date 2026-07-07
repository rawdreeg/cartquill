<?php
/**
 * In-memory License for tests.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class ArrayLicense implements License {

	/** @var list<string> Held plans (e.g. ['pro'] or ['ai']). */
	private array $plans;

	/** @var array<string, int> */
	private array $limits;

	/**
	 * @param list<string>        $plans
	 * @param array<string, int>  $limits
	 */
	public function __construct( array $plans = array(), array $limits = array() ) {
		$this->plans  = $plans;
		$this->limits = $limits;
	}

	public function is_active( string $capability ): bool {
		foreach ( $this->plans as $plan ) {
			if ( in_array( $capability, Plans::grants( $plan ), true ) ) {
				return true;
			}
		}
		return false;
	}

	public function plan(): string {
		return Plans::highest_tier( $this->plans );
	}

	public function limits(): array {
		return $this->limits;
	}
}
