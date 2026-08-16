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
 * seams from the tier Freemius reports, so the paid capabilities and numeric
 * limits follow the customer's subscription.
 *
 * The bridge runs in one of two modes, decided by cartquill_fs_owns_plan():
 *
 * AUTHORITATIVE (every premium build). Freemius is the only source of plan
 * truth. `plan_active_filter` answers purely from the reported tier and ignores
 * the value passed in, so {@see OptionLicense}'s stored keys cannot grant a
 * capability. This is the mode that matters: OptionLicense treats any non-empty
 * string as a held plan, by design, which without this would make the admin
 * license form a one-field unlock for anyone holding a copy of the premium zip.
 *
 * FALLBACK (dev/demo, opted into with CARTQUILL_LOCAL_LICENSE in wp-config.php).
 * Freemius merely *unions* capabilities on and never revokes a local grant, so
 * the add-ons can be built and demonstrated without a live subscription.
 *
 * In both modes a tier of '' means Freemius has no opinion, and the numeric
 * limits fall through to the defaults — which for an unlicensed premium install
 * are the uncapped core ones. That is the intended shape: without a licence the
 * paid capabilities are off and everything core ships stays uncapped, exactly as
 * it is in the free edition.
 *
 * The Freemius SDK is touched only inside the default provider, so the bridge's
 * logic is exercised DB-free by injecting a fake slug provider in tests.
 */
final class FreemiusBridge {

	/** @var callable(): string The current Freemius tier slug ('' when Freemius has no opinion). */
	private $provider;

	/** Whether Freemius is the sole authority on plan status. */
	private bool $owns_plan;

	/**
	 * @param callable(): string|null $provider  Returns the current Freemius tier slug
	 *                                           ('starter'|'growth'|'agency'|'pro'|''); defaults to
	 *                                           the SDK-backed provider, which reports '' without
	 *                                           the SDK. Tests inject a fake provider.
	 * @param bool|null               $owns_plan Whether Freemius is authoritative; defaults to
	 *                                           cartquill_fs_owns_plan().
	 */
	public function __construct( ?callable $provider = null, ?bool $owns_plan = null ) {
		$this->provider  = $provider ?? self::default_provider();
		$this->owns_plan = $owns_plan ?? self::default_owns_plan();
	}

	/**
	 * Decide whether the capability is active.
	 *
	 * Authoritative: the reported tier decides, alone — an unrecognized or empty
	 * tier grants nothing. Fallback: union the tier's grants onto the local
	 * decision, leaving a locally-active capability active.
	 *
	 * @param bool   $active     Whether the capability is active locally.
	 * @param string $capability The Plans::* capability being checked.
	 */
	public function plan_active_filter( bool $active, string $capability ): bool {
		$tier   = ( $this->provider )();
		$grants = '' !== $tier && in_array( $capability, Plans::grants( $tier ), true );

		if ( $this->owns_plan ) {
			return $grants;
		}

		return $active || $grants;
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
	 * Drive the displayed plan from the reported tier.
	 *
	 * Authoritative: the tier is the whole answer, including '' for an install
	 * with no subscription — the locally computed plan is never shown, because a
	 * stored key is not evidence of anything. Fallback: keep the local plan when
	 * Freemius has no opinion.
	 *
	 * @param string $plan The locally computed plan.
	 */
	public function plan_filter( string $plan ): string {
		$tier = ( $this->provider )();
		if ( $this->owns_plan ) {
			return $tier;
		}
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
	 * resolve; an unrecognized slug simply grants nothing.
	 *
	 * @return callable(): string
	 */
	private static function default_provider(): callable {
		return static function (): string {
			try {
				if ( function_exists( 'cartquill_fs' ) && \cartquill_fs()?->can_use_premium_code() ) {
					return strtolower( trim( (string) \cartquill_fs()->get_plan_name() ) );
				}
			} catch ( \Throwable $e ) {
				// Freemius not ready — fall through to "no opinion".
			}
			return '';
		};
	}

	/**
	 * Authoritative wherever the premium bootstrap is present, which is every
	 * premium build. Absent (the DB-free test harness, or a tree where
	 * src/freemius.php was not loaded) the bridge keeps its permissive fallback.
	 */
	private static function default_owns_plan(): bool {
		return function_exists( 'cartquill_fs_owns_plan' ) && \cartquill_fs_owns_plan();
	}
}
