<?php
/**
 * Subscription tiers: the Starter/Growth/Agency entitlements map and how the
 * license derives its held plan, numeric limits, and the action cap from it.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\OptionLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Metering\InMemoryUsageStore;
use CartQuill\Metering\UsageMeter;
use CartQuill\Support\FixedClock;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class TiersTest extends TestCase {

	use MockeryPHPUnitIntegration;

	public function test_each_tier_has_the_advertised_entitlements(): void {
		$this->assertSame(
			array( 'actions' => 2000, 'workflows' => 5, 'conditional_logic' => 0 ),
			Plans::entitlements( Plans::STARTER )
		);
		$this->assertSame(
			array( 'actions' => 25000, 'workflows' => 0, 'conditional_logic' => 1 ),
			Plans::entitlements( Plans::GROWTH ),
			'0 workflows means unlimited; conditional logic on'
		);
		$this->assertSame(
			array( 'actions' => 150000, 'workflows' => 0, 'conditional_logic' => 1 ),
			Plans::entitlements( Plans::AGENCY )
		);
		$this->assertSame( array(), Plans::entitlements( 'nonsense' ) );
	}

	public function test_highest_tier_wins_when_several_are_held(): void {
		$this->assertSame( Plans::GROWTH, Plans::highest_tier( array( Plans::STARTER, Plans::GROWTH ) ) );
		$this->assertSame( Plans::AGENCY, Plans::highest_tier( array( Plans::AGENCY, Plans::STARTER ) ) );
		$this->assertSame( Plans::STARTER, Plans::highest_tier( array( Plans::STARTER ) ) );
		$this->assertSame( '', Plans::highest_tier( array() ), 'no tier held' );
		$this->assertSame( '', Plans::highest_tier( array( Plans::AI ) ), 'add-ons are not tiers' );
	}

	public function test_tiers_form_a_capability_ladder(): void {
		// Every tier ships the five integrations, so any tier unlocks automations.
		$this->assertContains( Plans::AUTOMATIONS, Plans::grants( Plans::STARTER ) );
		$this->assertContains( Plans::STARTER, Plans::grants( Plans::STARTER ) );

		// Starter unlocks automations only — the paid add-ons stay locked.
		$starter = new ArrayLicense( array( Plans::STARTER ) );
		$this->assertTrue( $starter->is_active( Plans::AUTOMATIONS ) );
		$this->assertFalse( $starter->is_active( Plans::AI ) );
		$this->assertFalse( $starter->is_active( Plans::DELIVERABILITY ) );

		// Growth folds in AI generation, but not Deliverability.
		$growth = new ArrayLicense( array( Plans::GROWTH ) );
		$this->assertTrue( $growth->is_active( Plans::AUTOMATIONS ) );
		$this->assertTrue( $growth->is_active( Plans::AI ) );
		$this->assertFalse( $growth->is_active( Plans::DELIVERABILITY ) );

		// Agency folds in both AI and Deliverability.
		$agency = new ArrayLicense( array( Plans::AGENCY ) );
		$this->assertTrue( $agency->is_active( Plans::AUTOMATIONS ) );
		$this->assertTrue( $agency->is_active( Plans::AI ) );
		$this->assertTrue( $agency->is_active( Plans::DELIVERABILITY ) );
	}

	public function test_array_license_reports_its_held_tier(): void {
		$this->assertSame( Plans::GROWTH, ( new ArrayLicense( array( Plans::GROWTH ) ) )->plan() );
		$this->assertSame( '', ( new ArrayLicense() )->plan() );
		$this->assertSame( '', ( new ArrayLicense( array( Plans::AI ) ) )->plan(), 'an add-on is not a tier' );
	}

	public function test_option_license_derives_limits_from_the_held_tier(): void {
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array( Plans::GROWTH => 'KEY-1' ) );
		Functions\when( 'apply_filters' )->returnArg( 2 ); // pass the scaffold defaults through

		$license = new OptionLicense();

		$this->assertSame( Plans::GROWTH, $license->plan() );
		$this->assertSame( 25000, $license->limits()['actions'] );
		$this->assertSame( 0, $license->limits()['workflows'] );
		$this->assertSame( 1, $license->limits()['conditional_logic'] );

		Monkey\tearDown();
	}

	public function test_option_license_without_a_tier_is_uncapped(): void {
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() ); // no tier, no add-on
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$license = new OptionLicense();

		$this->assertSame( '', $license->plan() );
		$this->assertGreaterThanOrEqual( 1000000, $license->limits()['actions'], 'no tier does not throttle the free core' );
		$this->assertSame( 0, $license->limits()['workflows'], 'unlimited workflows' );
		$this->assertSame( 1, $license->limits()['conditional_logic'], 'no tier does not disable conditions' );

		Monkey\tearDown();
	}

	public function test_plan_limits_filter_overrides_the_scaffold(): void {
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array( Plans::STARTER => 'KEY-1' ) );
		// Freemius (or a site owner) overrides the scaffold numbers entirely; the
		// plan() seam still passes the computed tier through untouched.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) {
				return 'cartquill_plan_limits' === $tag
					? array( 'actions' => 9999, 'workflows' => 42, 'conditional_logic' => 1 )
					: $value;
			}
		);

		$limits = ( new OptionLicense() )->limits();

		$this->assertSame( 9999, $limits['actions'] );
		$this->assertSame( 42, $limits['workflows'] );
		$this->assertSame( 1, $limits['conditional_logic'] );

		Monkey\tearDown();
	}

	public function test_the_meter_cap_tracks_the_held_tier(): void {
		$meter = new UsageMeter(
			new InMemoryUsageStore(),
			new ArrayLicense( array( Plans::STARTER ), Plans::entitlements( Plans::STARTER ) ),
			new FixedClock( 1_700_000_000 )
		);

		$this->assertSame( 2000, $meter->limit(), 'the action cap is the tier entitlement' );
	}

	public function test_meter_cap_tracks_the_tier_through_the_option_license(): void {
		// End-to-end: the option-backed license derives the cap from the held tier's
		// entitlements, and the meter reads it — no manual limits wiring.
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array( Plans::AGENCY => 'KEY-1' ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$meter = new UsageMeter( new InMemoryUsageStore(), new OptionLicense(), new FixedClock( 1_700_000_000 ) );

		$this->assertSame( 150000, $meter->limit() );

		Monkey\tearDown();
	}
}
