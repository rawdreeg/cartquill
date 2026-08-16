<?php
/**
 * The Freemius bridge drives the licensing filters from the reported tier slug.
 *
 * It has two modes and the difference between them is the whole point, so every
 * test here states which one it is exercising rather than letting the ambient
 * environment pick: authoritative (every premium build — Freemius decides alone)
 * and fallback (CARTQUILL_LOCAL_LICENSE — Freemius only adds).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Licensing\FreemiusBridge;
use CartQuill\Licensing\Plans;
use PHPUnit\Framework\TestCase;

final class FreemiusBridgeTest extends TestCase {

	/** Production: Freemius is the only source of plan truth. */
	private function authoritative( string $tier ): FreemiusBridge {
		return new FreemiusBridge( static fn (): string => $tier, true );
	}

	/** Dev/demo: Freemius unions onto whatever the local key store decided. */
	private function fallback( string $tier ): FreemiusBridge {
		return new FreemiusBridge( static fn (): string => $tier, false );
	}

	public function test_growth_folds_ai_and_automations_into_the_active_filter(): void {
		$bridge = $this->authoritative( Plans::GROWTH );

		$this->assertTrue( $bridge->plan_active_filter( false, Plans::AI ) );
		$this->assertTrue( $bridge->plan_active_filter( false, Plans::AUTOMATIONS ) );
		$this->assertTrue( $bridge->plan_active_filter( false, Plans::GROWTH ) );
		$this->assertFalse( $bridge->plan_active_filter( false, Plans::PRO ) );
	}

	public function test_growth_drives_limits_and_plan(): void {
		$bridge = $this->authoritative( Plans::GROWTH );

		$this->assertSame( Plans::entitlements( Plans::GROWTH ), $bridge->plan_limits_filter( array(), array() ) );
		$this->assertSame( Plans::GROWTH, $bridge->plan_filter( '' ) );
	}

	public function test_agency_unlocks_ai_and_automations(): void {
		$agency = $this->authoritative( Plans::AGENCY );
		$this->assertTrue( $agency->plan_active_filter( false, Plans::AI ) );
		$this->assertTrue( $agency->plan_active_filter( false, Plans::AUTOMATIONS ) );
	}

	public function test_starter_locks_ai_but_unlocks_automations(): void {
		$bridge = $this->authoritative( Plans::STARTER );

		$this->assertFalse( $bridge->plan_active_filter( false, Plans::AI ) );
		$this->assertTrue( $bridge->plan_active_filter( false, Plans::AUTOMATIONS ) );
	}

	/**
	 * The one that matters. OptionLicense counts any non-empty string as a held
	 * plan, so if a local grant survived into production, typing a character into
	 * the admin license form would unlock the paid add-ons for anyone holding a
	 * copy of the premium zip.
	 */
	public function test_authoritative_mode_discards_a_local_grant(): void {
		$this->assertFalse(
			$this->authoritative( Plans::STARTER )->plan_active_filter( true, Plans::AI ),
			'Starter does not include AI, and a locally stored key must not add it'
		);
		$this->assertFalse(
			$this->authoritative( '' )->plan_active_filter( true, Plans::AUTOMATIONS ),
			'no subscription grants nothing, whatever the option table says'
		);
	}

	public function test_authoritative_mode_shows_no_plan_without_a_subscription(): void {
		$bridge = $this->authoritative( '' );

		$this->assertSame( '', $bridge->plan_filter( Plans::GROWTH ), 'a stored key is not evidence of a plan' );
	}

	/**
	 * An unlicensed premium install must behave like the free edition — complete
	 * and unmetered — so the numeric limits fall through to the uncapped defaults
	 * rather than to a zeroed trial.
	 */
	public function test_no_subscription_leaves_the_uncapped_defaults_standing(): void {
		$defaults = array( 'actions' => 1000000, 'workflows' => 0, 'conditional_logic' => 1 );

		$this->assertSame( $defaults, $this->authoritative( '' )->plan_limits_filter( $defaults, array() ) );
	}

	public function test_no_freemius_opinion_passes_everything_through_in_fallback_mode(): void {
		$bridge = $this->fallback( '' );

		// $active is returned untouched, both ways.
		$this->assertTrue( $bridge->plan_active_filter( true, Plans::AI ) );
		$this->assertFalse( $bridge->plan_active_filter( false, Plans::AI ) );

		// Defaults and the computed plan pass through unchanged.
		$defaults = array( 'actions' => 123, 'workflows' => 4, 'conditional_logic' => 1 );
		$this->assertSame( $defaults, $bridge->plan_limits_filter( $defaults, array() ) );
		$this->assertSame( Plans::STARTER, $bridge->plan_filter( Plans::STARTER ) );
	}

	public function test_fallback_mode_never_revokes_a_local_grant(): void {
		// Even where Freemius would not grant it, a locally-active capability stays active.
		$this->assertTrue( $this->fallback( Plans::STARTER )->plan_active_filter( true, Plans::AI ) );
	}

	public function test_fallback_mode_still_adds_what_the_tier_grants(): void {
		$this->assertTrue( $this->fallback( Plans::GROWTH )->plan_active_filter( false, Plans::AI ) );
	}
}
