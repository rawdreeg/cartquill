<?php
/**
 * License gating: plan grants, the Pro bundle, and the option-backed store.
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
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class LicensingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	public function test_a_plan_grants_only_itself_except_pro(): void {
		$this->assertSame( array( Plans::AI ), Plans::grants( Plans::AI ) );
		$this->assertSame(
			array( Plans::AI, Plans::DELIVERABILITY, Plans::AUTOMATIONS, Plans::PRO ),
			Plans::grants( Plans::PRO ),
			'the Pro bundle unlocks every add-on'
		);
	}

	public function test_array_license_gates_by_held_plan(): void {
		$ai = new ArrayLicense( array( Plans::AI ) );
		$this->assertTrue( $ai->is_active( Plans::AI ) );
		$this->assertFalse( $ai->is_active( Plans::DELIVERABILITY ) );

		$pro = new ArrayLicense( array( Plans::PRO ) );
		$this->assertTrue( $pro->is_active( Plans::AI ) );
		$this->assertTrue( $pro->is_active( Plans::DELIVERABILITY ) );
	}

	public function test_no_license_activates_nothing(): void {
		$none = new ArrayLicense();
		$this->assertFalse( $none->is_active( Plans::AI ) );
		$this->assertFalse( $none->is_active( Plans::DELIVERABILITY ) );
	}

	public function test_option_license_marks_a_plan_active_from_a_stored_key(): void {
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array( Plans::DELIVERABILITY => 'KEY-123' ) );
		Functions\when( 'apply_filters' )->returnArg( 2 ); // pass through the $active value

		$license = new OptionLicense();

		$this->assertSame( array( Plans::DELIVERABILITY ), $license->held_plans() );
		$this->assertTrue( $license->is_active( Plans::DELIVERABILITY ) );
		$this->assertFalse( $license->is_active( Plans::AI ) );

		Monkey\tearDown();
	}

	public function test_option_license_lets_a_backend_override_via_filter(): void {
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() ); // nothing held locally
		Functions\when( 'apply_filters' )->justReturn( true ); // Freemius says active

		$this->assertTrue( ( new OptionLicense() )->is_active( Plans::AI ) );

		Monkey\tearDown();
	}

	public function test_option_license_lets_a_backend_drive_the_displayed_plan(): void {
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() ); // no key persisted locally
		// The `cartquill_plan` seam lets Freemius drive the tier without a stored key.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, $value ) => 'cartquill_plan' === $tag ? Plans::GROWTH : $value
		);

		$this->assertSame( Plans::GROWTH, ( new OptionLicense() )->plan() );

		Monkey\tearDown();
	}
}
