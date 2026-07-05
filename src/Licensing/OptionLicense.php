<?php
/**
 * Option-backed License (scaffold).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Stores which plans a store holds (and their license keys) in a wp_option, and
 * answers the gate from it.
 *
 * SCAFFOLD: in production the plan-active decision is owned by Freemius (which
 * validates keys against the store). The `flowforge_plan_active` filter is the
 * seam where the Freemius SDK overrides this — until then, entering a key marks
 * the plan active locally so the add-ons can be developed and demoed.
 *
 * Note: these are *entitlement* keys (they identify a purchase), not ESP send
 * credentials. They are short-lived scaffold state that Freemius replaces, so
 * they are stored plainly here; the send-credential encryption the data model
 * mandates applies to the Deliverability add-on's ESP key, not to this.
 */
final class OptionLicense implements License {

	public const OPTION = 'flowforge_license';

	public function is_active( string $capability ): bool {
		$active = false;
		foreach ( $this->held_plans() as $plan ) {
			if ( in_array( $capability, Plans::grants( $plan ), true ) ) {
				$active = true;
				break;
			}
		}

		/**
		 * Let a real licensing backend (Freemius) decide plan status.
		 *
		 * @param bool   $active     Whether the capability is active locally.
		 * @param string $capability The Plans::* capability being checked.
		 */
		return (bool) \apply_filters( 'flowforge_plan_active', $active, $capability );
	}

	/**
	 * Plans the store currently holds a (non-empty) key for.
	 *
	 * @return list<string>
	 */
	public function held_plans(): array {
		$data  = $this->data();
		$plans = array();
		foreach ( Plans::all() as $plan ) {
			if ( ! empty( $data[ $plan ] ) ) {
				$plans[] = $plan;
			}
		}
		return $plans;
	}

	/**
	 * Store (or clear) the license key for a plan.
	 */
	public function set_key( string $plan, string $key ): void {
		if ( ! in_array( $plan, Plans::all(), true ) ) {
			return;
		}
		$data          = $this->data();
		$data[ $plan ] = \sanitize_text_field( $key );
		\update_option( self::OPTION, $data );
	}

	public function key_for( string $plan ): string {
		return (string) ( $this->data()[ $plan ] ?? '' );
	}

	/**
	 * @return array<string, string>
	 */
	private function data(): array {
		$data = \get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}
}
