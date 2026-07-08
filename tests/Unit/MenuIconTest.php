<?php
/**
 * The branded admin-menu icon: a base64 SVG data URI (the form WordPress's
 * svg-painter recolors to match the admin color scheme).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Admin\MenuIcon;
use CartQuill\Admin\SettingsPage;
use CartQuill\Settings\OptionsSettings;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class MenuIconTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_data_uri_is_a_base64_encoded_svg(): void {
		$uri = MenuIcon::data_uri();

		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $uri );

		$svg = base64_decode( substr( $uri, strlen( 'data:image/svg+xml;base64,' ) ), true );
		$this->assertIsString( $svg );
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 512 512"', $svg, 'the cartquill.com brand mark' );
	}

	public function test_settings_page_registers_the_menu_with_the_brand_icon(): void {
		Functions\when( '__' )->returnArg();
		$captured = array();
		Functions\when( 'add_menu_page' )->alias(
			static function ( ...$args ) use ( &$captured ) {
				$captured = $args;
				return 'toplevel_page_cartquill';
			}
		);

		( new SettingsPage( new OptionsSettings() ) )->add_menu();

		$this->assertSame( MenuIcon::data_uri(), $captured[5], 'the menu uses the brand icon, not a stock dashicon' );
	}
}
