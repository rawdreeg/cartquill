<?php
/**
 * wp_options-backed settings accessors.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FlowForge\Settings\OptionsSettings;
use PHPUnit\Framework\TestCase;

final class OptionsSettingsTest extends TestCase {

	protected function setUp(): void {
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_attribution_window_defaults_to_seven_days_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( 7, ( new OptionsSettings() )->attribution_window_days() );
	}

	public function test_attribution_window_reads_the_configured_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'attribution_window_days' => 14 ) );
		$this->assertSame( 14, ( new OptionsSettings() )->attribution_window_days() );
	}

	public function test_attribution_window_falls_back_when_invalid(): void {
		Functions\when( 'get_option' )->justReturn( array( 'attribution_window_days' => 0 ) );
		$this->assertSame( 7, ( new OptionsSettings() )->attribution_window_days() );
	}
}
