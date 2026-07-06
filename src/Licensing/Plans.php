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
 * automations) and the license expands the bundle for it. The tiers slice (#69)
 * maps the Starter/Growth/Agency plans onto the automations capability.
 */
final class Plans {

	public const AI            = 'ai';
	public const DELIVERABILITY = 'deliverability';
	public const AUTOMATIONS   = 'automations';
	public const PRO           = 'pro';

	/**
	 * The capabilities a held plan grants.
	 *
	 * @return list<string>
	 */
	public static function grants( string $plan ): array {
		if ( self::PRO === $plan ) {
			return array( self::AI, self::DELIVERABILITY, self::AUTOMATIONS, self::PRO );
		}
		return array( $plan );
	}

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::AI, self::DELIVERABILITY, self::AUTOMATIONS, self::PRO );
	}
}
