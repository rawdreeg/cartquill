<?php
/**
 * The paid plans and what each unlocks.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * FlowForge sells two add-ons à la carte plus a Pro bundle. The Pro bundle
 * grants both add-ons, so gating logic asks about a capability (ai /
 * deliverability) and the license expands the bundle for it.
 */
final class Plans {

	public const AI            = 'ai';
	public const DELIVERABILITY = 'deliverability';
	public const PRO           = 'pro';

	/**
	 * The capabilities a held plan grants.
	 *
	 * @return list<string>
	 */
	public static function grants( string $plan ): array {
		if ( self::PRO === $plan ) {
			return array( self::AI, self::DELIVERABILITY, self::PRO );
		}
		return array( $plan );
	}

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::AI, self::DELIVERABILITY, self::PRO );
	}
}
