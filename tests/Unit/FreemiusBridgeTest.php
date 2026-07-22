<?php
/**
 * The Freemius bridge drives the licensing filters from the reported tier slug,
 * and passes everything through untouched when Freemius has no opinion.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Licensing\FreemiusBridge;
use CartQuill\Licensing\Plans;
use PHPUnit\Framework\TestCase;

final class FreemiusBridgeTest extends TestCase {

	/**
	 * A bridge whose provider always reports the given tier slug.
	 */
	private function bridge( string $tier ): FreemiusBridge {
		return new FreemiusBridge( static fn (): string => $tier );
	}

	public function test_growth_folds_ai_and_automations_into_the_active_filter(): void {
		$bridge = $this->bridge( Plans::GROWTH );

		$this->assertTrue( $bridge->plan_active_filter( false, Plans::AI ) );
		$this->assertTrue( $bridge->plan_active_filter( false, Plans::AUTOMATIONS ) );
		$this->assertTrue( $bridge->plan_active_filter( false, Plans::GROWTH ) );
		$this->assertFalse( $bridge->plan_active_filter( false, Plans::PRO ) );
	}

	public function test_growth_drives_limits_and_plan(): void {
		$bridge = $this->bridge( Plans::GROWTH );

		$this->assertSame( Plans::entitlements( Plans::GROWTH ), $bridge->plan_limits_filter( array(), array() ) );
		$this->assertSame( Plans::GROWTH, $bridge->plan_filter( '' ) );
	}

	public function test_agency_unlocks_ai_and_automations(): void {
		$agency = $this->bridge( Plans::AGENCY );
		$this->assertTrue( $agency->plan_active_filter( false, Plans::AI ) );
		$this->assertTrue( $agency->plan_active_filter( false, Plans::AUTOMATIONS ) );
	}

	public function test_starter_locks_ai_but_unlocks_automations(): void {
		$bridge = $this->bridge( Plans::STARTER );

		$this->assertFalse( $bridge->plan_active_filter( false, Plans::AI ) );
		$this->assertTrue( $bridge->plan_active_filter( false, Plans::AUTOMATIONS ) );
	}

	public function test_no_freemius_opinion_passes_everything_through(): void {
		$bridge = $this->bridge( '' );

		// $active is returned untouched, both ways.
		$this->assertTrue( $bridge->plan_active_filter( true, Plans::AI ) );
		$this->assertFalse( $bridge->plan_active_filter( false, Plans::AI ) );

		// Defaults and the computed plan pass through unchanged.
		$defaults = array( 'actions' => 123, 'workflows' => 4, 'conditional_logic' => 1 );
		$this->assertSame( $defaults, $bridge->plan_limits_filter( $defaults, array() ) );
		$this->assertSame( Plans::STARTER, $bridge->plan_filter( Plans::STARTER ) );
	}

	public function test_active_filter_never_revokes_a_local_grant(): void {
		// Even where Freemius would not grant it, a locally-active capability stays active.
		$this->assertTrue( $this->bridge( Plans::STARTER )->plan_active_filter( true, Plans::AI ) );
	}
}
