<?php
/**
 * Option-backed License (scaffold).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Stores which plans a store holds (and their license keys) in a wp_option, and
 * answers the gate from it.
 *
 * SCAFFOLD: in production the plan-active decision is owned by Freemius (which
 * validates keys against the store). The `cartquill_plan_active` filter is the
 * seam where the Freemius SDK overrides this — until then, entering a key marks
 * the plan active locally so the add-ons can be developed and demoed.
 *
 * Note: these are *entitlement* keys (they identify a purchase), not ESP send
 * credentials. They are short-lived scaffold state that Freemius replaces, so
 * they are stored plainly here; the send-credential encryption the data model
 * mandates applies to the Deliverability add-on's ESP key, not to this.
 */
final class OptionLicense implements License {

	public const OPTION = 'cartquill_license';

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
		return (bool) \apply_filters( 'cartquill_plan_active', $active, $capability );
	}

	/**
	 * The held plan's numeric limits. SCAFFOLD: a generous default so metering
	 * does not bite before the tiers slice (#69) wires the real per-tier numbers;
	 * the `cartquill_plan_limits` filter is the seam where Freemius/#69 overrides
	 * it, mirroring the `cartquill_plan_active` seam used by is_active().
	 *
	 * @return array<string, int>
	 */
	public function limits(): array {
		$defaults = array( 'actions' => 1000000 );

		/**
		 * Filter the held plan's numeric limits (e.g. the monthly action cap).
		 *
		 * @param array<string, int> $defaults The scaffold defaults.
		 * @param list<string>       $plans    Plans the store holds.
		 */
		$limits = (array) \apply_filters( 'cartquill_plan_limits', $defaults, $this->held_plans() );

		return array_map( 'intval', $limits );
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
		\update_option( self::OPTION, $data, false );
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
