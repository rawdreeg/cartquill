<?php
/**
 * The React builder admin page: it enqueues the compiled bundle and hands the app
 * its REST root, nonce, and flow id.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Admin\FlowBuilderPage;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'CARTQUILL_PATH' ) ) {
	define( 'CARTQUILL_PATH', '/nonexistent/cartquill/' ); // No built asset file → enqueue uses safe defaults.
}
if ( ! defined( 'CARTQUILL_FILE' ) ) {
	define( 'CARTQUILL_FILE', '/nonexistent/cartquill/cartquill.php' );
}
if ( ! defined( 'CARTQUILL_VERSION' ) ) {
	define( 'CARTQUILL_VERSION', '0.1.0-test' );
}

final class FlowBuilderPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enqueue_registers_the_bundle_and_localizes_rest_config(): void {
		$enqueued  = array();
		$styles    = array();
		$rtl       = array();
		$localized = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/wp-content/plugins/cartquill/' . $path );
		Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( ...$args ) use ( &$enqueued ) {
				$enqueued = $args;
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( ...$args ) use ( &$styles ) {
				$styles = $args;
			}
		);
		Functions\when( 'wp_style_add_data' )->alias(
			static function ( ...$args ) use ( &$rtl ) {
				$rtl = $args;
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized = compact( 'handle', 'name', 'data' );
			}
		);

		( new FlowBuilderPage() )->enqueue( 7 );

		$this->assertSame( 'cartquill-flow-builder', $enqueued[0], 'the script handle' );
		$this->assertStringContainsString( 'assets/builder/build/index.js', $enqueued[1], 'the compiled bundle' );
		$this->assertSame( array(), $enqueued[2], 'no built manifest in a source checkout → empty deps' );

		$this->assertSame( 'cartquill-flow-builder-style', $styles[0], 'the style handle' );
		$this->assertStringContainsString( 'assets/builder/build/style-index.css', $styles[1], 'the compiled stylesheet' );
		$this->assertSame( array( 'cartquill-flow-builder-style', 'rtl', 'replace' ), $rtl, 'the -rtl variant is wired' );

		$this->assertSame( 'cartquillBuilder', $localized['name'] );
		$this->assertSame( 'https://example.test/wp-json/', $localized['data']['root'] );
		$this->assertSame( 'nonce123', $localized['data']['nonce'] );
		$this->assertSame( 7, $localized['data']['flowId'] );
		$this->assertSame( array(), $localized['data']['features'], 'core ships no builder features; add-ons contribute via the filter' );
	}
}
