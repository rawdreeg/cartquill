<?php
/**
 * Onboarding state and the one-time post-activation redirect gate.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Admin\Onboarding;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class OnboardingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_should_redirect_only_when_freshly_activated_and_not_onboarded(): void {
		$this->assertTrue( Onboarding::should_redirect( true, false ) );   // just activated, not done
		$this->assertFalse( Onboarding::should_redirect( true, true ) );   // done already
		$this->assertFalse( Onboarding::should_redirect( false, false ) ); // no activation flag
	}

	public function test_is_complete_reads_the_option(): void {
		Functions\when( 'get_option' )->justReturn( true );
		$this->assertTrue( ( new Onboarding() )->is_complete() );
	}

	public function test_mark_complete_persists_the_option(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( Onboarding::OPTION_COMPLETE, true );

		( new Onboarding() )->mark_complete();
	}

	public function test_consume_redirect_is_true_once_then_clears(): void {
		Functions\when( 'get_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'delete_transient' )->once()->with( Onboarding::TRANSIENT_REDIRECT );

		$this->assertTrue( ( new Onboarding() )->consume_redirect() );
	}

	public function test_consume_redirect_is_false_once_onboarded(): void {
		Functions\when( 'get_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( true ); // already complete
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->assertFalse( ( new Onboarding() )->consume_redirect() );
	}

	public function test_flag_for_redirect_sets_a_transient(): void {
		Functions\expect( 'set_transient' )
			->once()
			->with( Onboarding::TRANSIENT_REDIRECT, 1, Onboarding::REDIRECT_TTL );

		( new Onboarding() )->flag_for_redirect();
	}
}
