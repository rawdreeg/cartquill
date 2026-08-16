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
 * Read the `held_plans()` test carefully before trusting this class: any
 * non-empty string counts as holding the plan. Nothing here is verified against
 * anything, and it never was — which is exactly why
 * {@see FreemiusBridge::plan_active_filter()} overrides rather than unions on a
 * premium build. In production this class answers into a filter that discards
 * its answer; it decides anything only under CARTQUILL_LOCAL_LICENSE, the
 * wp-config.php opt-in that exists so the add-ons can be developed and demoed.
 *
 * Its remaining production job is the *shape* of the defaults: the limits below
 * are what an install with no subscription runs under, and they are deliberately
 * uncapped, because a premium build without a licence must behave exactly like
 * the free edition — complete and unmetered — rather than like a crippled trial.
 *
 * Note: these are *entitlement* keys (they identify a purchase), not ESP send
 * credentials. They hold no access to anything, so they are stored plainly; the
 * credential encryption the data model mandates applies to add-on connection
 * credentials, not to this.
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

	public function plan(): string {
		/**
		 * Let a real licensing backend (Freemius) drive the displayed tier without
		 * persisting a license key to the option. Mirrors the `cartquill_plan_active`
		 * and `cartquill_plan_limits` seams; the FreemiusBridge hooks it.
		 *
		 * @param string $plan The tier derived from the local key store.
		 */
		return (string) \apply_filters( 'cartquill_plan', Plans::highest_tier( $this->held_plans() ) );
	}

	/**
	 * The held plan's numeric limits, derived from the held subscription tier's
	 * entitlements. A store on no tier (the free core, or an à la carte add-on) is
	 * uncapped so metering never throttles it.
	 *
	 * SCAFFOLD: the tier is read from the local key store; in production Freemius
	 * owns the plan. The `cartquill_plan_limits` filter is the seam where Freemius
	 * overrides the numbers, mirroring the `cartquill_plan_active` seam is_active()
	 * uses.
	 *
	 * @return array<string, int>
	 */
	public function limits(): array {
		$plan     = $this->plan();
		$defaults = '' !== $plan
			? Plans::entitlements( $plan )
			: array(
				'actions'           => 1000000,
				'workflows'         => 0,
				'conditional_logic' => 1,
			);

		/**
		 * Filter the held plan's numeric limits (action cap, workflow cap,
		 * conditional-logic flag).
		 *
		 * @param array<string, int> $defaults The tier's entitlements (or the uncapped default).
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
