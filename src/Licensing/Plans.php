<?php
/**
 * The paid plans and what each unlocks.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * CartQuill sells add-ons à la carte plus a Pro bundle. The Pro bundle grants
 * every add-on, so gating logic asks about a capability (ai / deliverability /
 * automations) and the license expands the bundle for it.
 *
 * The automation product itself is sold on the Starter/Growth/Agency subscription
 * tiers the marketing site advertises. A tier is a *held plan* like any other, and
 * holding one grants the automations capability (every tier ships the five
 * integrations); the tiers differ only in their numeric {@see self::entitlements()}
 * — the monthly action cap, the active-workflow cap, and whether conditional logic
 * is unlocked.
 */
final class Plans {

	public const AI            = 'ai';
	public const DELIVERABILITY = 'deliverability';
	public const AUTOMATIONS   = 'automations';
	public const PRO           = 'pro';

	public const STARTER = 'starter';
	public const GROWTH  = 'growth';
	public const AGENCY  = 'agency';

	/** Subscription tiers, lowest to highest. */
	public const TIERS = array( self::STARTER, self::GROWTH, self::AGENCY );

	/**
	 * A tier's numeric entitlements: the monthly `actions` cap, the active
	 * `workflows` cap (0 = unlimited), and whether `conditional_logic` (data-driven
	 * branching steps) is unlocked (0/1). Returns an empty array for a non-tier.
	 *
	 * @return array<string, int>
	 */
	public static function entitlements( string $tier ): array {
		$map = array(
			self::STARTER => array( 'actions' => 2000, 'workflows' => 5, 'conditional_logic' => 0 ),
			self::GROWTH  => array( 'actions' => 25000, 'workflows' => 0, 'conditional_logic' => 1 ),
			self::AGENCY  => array( 'actions' => 150000, 'workflows' => 0, 'conditional_logic' => 1 ),
		);

		return $map[ $tier ] ?? array();
	}

	/**
	 * The highest tier among the held plans (so a store holding several reports the
	 * most generous), or '' when none of them is a tier.
	 *
	 * @param list<string> $held
	 */
	public static function highest_tier( array $held ): string {
		$highest = '';
		foreach ( self::TIERS as $tier ) {
			if ( in_array( $tier, $held, true ) ) {
				$highest = $tier;
			}
		}
		return $highest;
	}

	/**
	 * The capabilities a held plan grants. Pro unlocks every add-on; a subscription
	 * tier unlocks the automations capability (its integrations); any other plan
	 * grants only itself.
	 *
	 * @return list<string>
	 */
	public static function grants( string $plan ): array {
		if ( self::PRO === $plan ) {
			return array( self::AI, self::DELIVERABILITY, self::AUTOMATIONS, self::PRO );
		}
		if ( in_array( $plan, self::TIERS, true ) ) {
			return array( $plan, self::AUTOMATIONS );
		}
		return array( $plan );
	}

	/**
	 * Every plan a store can hold: the à la carte add-ons and the Pro bundle, plus
	 * the three subscription tiers.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::AI, self::DELIVERABILITY, self::AUTOMATIONS, self::PRO, self::STARTER, self::GROWTH, self::AGENCY );
	}
}
