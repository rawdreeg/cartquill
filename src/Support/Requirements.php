<?php
/**
 * Dependency checks (pure logic, unit-tested).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The plugin hard-depends on WooCommerce 8+. The version comparison lives here
 * as a pure function so it can be tested without WordPress, then wrapped by the
 * activation hook and runtime boot guard.
 */
final class Requirements {

	public const MIN_WOOCOMMERCE = '8.0';

	/**
	 * @param bool        $active  Whether WooCommerce is loaded.
	 * @param string|null $version Its version, if known.
	 */
	public static function woocommerce_satisfied( bool $active, ?string $version ): bool {
		if ( ! $active || null === $version || '' === $version ) {
			return false;
		}
		return version_compare( $version, self::MIN_WOOCOMMERCE, '>=' );
	}
}
