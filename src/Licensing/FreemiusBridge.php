<?php
/**
 * Bridges the Freemius subscription tier into CartQuill's licensing filters.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Drives the `cartquill_plan_active`, `cartquill_plan_limits`, and `cartquill_plan`
 * seams from the tier Freemius reports, so in production the paid capabilities and
 * numeric limits follow the customer's subscription.
 *
 * It does NOT replace {@see OptionLicense}, which stays the dev/manual fallback:
 * when Freemius has no opinion (no SDK, or the site cannot use premium code) the
 * provider returns '' and every filter passes its input straight through. The
 * active filter only ever *unions* capabilities on — it never revokes a grant the
 * local license already made.
 *
 * The Freemius SDK is touched only inside the default provider, so the bridge's
 * logic is exercised DB-free by injecting a fake slug provider in tests.
 */
final class FreemiusBridge {

	/** @var callable(): string The current Freemius tier slug ('' when Freemius has no opinion). */
	private $provider;

	/**
	 * @param callable(): string|null $provider Returns the current Freemius tier slug
	 *                                          ('starter'|'growth'|'agency'|'pro'|''); defaults to
	 *                                          the SDK-backed provider, which is a no-op ('') without
	 *                                          the SDK. Tests inject a fake provider.
	 */
	public function __construct( ?callable $provider = null ) {
		$this->provider = $provider ?? self::default_provider();
	}

	/**
	 * Union the capabilities the reported tier grants onto the local decision. When
	 * Freemius has no opinion the local `$active` value passes through untouched.
	 *
	 * @param bool   $active     Whether the capability is active locally.
	 * @param string $capability The Plans::* capability being checked.
	 */
	public function plan_active_filter( bool $active, string $capability ): bool {
		$tier = ( $this->provider )();
		if ( '' === $tier ) {
			return $active;
		}
		return $active || in_array( $capability, Plans::grants( $tier ), true );
	}

	/**
	 * Replace the numeric limits with the reported tier's entitlements. A slug with
	 * no entitlements (e.g. 'pro', or '') leaves the passed-in defaults standing.
	 *
	 * @param array<string, int> $defaults The scaffold/tier defaults.
	 * @param list<string>       $plans    Plans the store holds locally.
	 * @return array<string, int>
	 */
	public function plan_limits_filter( array $defaults, array $plans ): array {
		$tier = ( $this->provider )();
		if ( '' !== $tier ) {
			$entitlements = Plans::entitlements( $tier );
			if ( array() !== $entitlements ) {
				return $entitlements;
			}
		}
		return $defaults;
	}

	/**
	 * Drive the displayed plan from the reported tier, falling back to the locally
	 * computed plan when Freemius has no opinion.
	 *
	 * @param string $plan The locally computed plan.
	 */
	public function plan_filter( string $plan ): string {
		$tier = ( $this->provider )();
		return '' !== $tier ? $tier : $plan;
	}

	/**
	 * Hook the three licensing seams. Degrades to a no-op if `add_filter` is absent
	 * (it never is in WordPress, but this keeps the class safe to construct in
	 * isolation, e.g. under the DB-free test harness).
	 */
	public function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		\add_filter( 'cartquill_plan_active', array( $this, 'plan_active_filter' ), 10, 2 );
		\add_filter( 'cartquill_plan_limits', array( $this, 'plan_limits_filter' ), 10, 2 );
		\add_filter( 'cartquill_plan', array( $this, 'plan_filter' ), 10, 1 );
	}

	/**
	 * The production slug provider: the current Freemius plan name, or '' when the
	 * SDK is absent or the site cannot use premium code. All SDK access is confined
	 * to this closure so the bridge's tested paths never touch Freemius.
	 *
	 * The returned slug is normalized (trimmed + lower-cased) and is expected to be
	 * one of the {@see Plans} tier slugs — the Freemius plans MUST use the unique
	 * names 'starter'/'growth'/'agency' (matching the dashboard) for their grants to
	 * resolve; an unrecognized slug simply grants nothing beyond the local license.
	 *
	 * @return callable(): string
	 */
	private static function default_provider(): callable {
		return static function (): string {
			try {
				if ( function_exists( 'cartquill_fs' ) && \cartquill_fs()->can_use_premium_code() ) {
					return strtolower( trim( (string) \cartquill_fs()->get_plan_name() ) );
				}
			} catch ( \Throwable $e ) {
				// Freemius not ready — fall through to "no opinion".
			}
			return '';
		};
	}
}
