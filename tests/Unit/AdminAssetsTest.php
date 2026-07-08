<?php
/**
 * The shared admin stylesheet loads on CartQuill screens only.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Admin\AdminAssets;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'CARTQUILL_FILE' ) ) {
	define( 'CARTQUILL_FILE', '/nonexistent/cartquill/cartquill.php' );
}
if ( ! defined( 'CARTQUILL_VERSION' ) ) {
	define( 'CARTQUILL_VERSION', '0.1.0-test' );
}

final class AdminAssetsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, mixed> */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->enqueued = array();
		Functions\when( 'plugins_url' )->alias( static fn( string $path ) => 'https://example.test/wp-content/plugins/cartquill/' . $path );
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( ...$args ): void {
				$this->enqueued[] = $args;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enqueues_the_stylesheet_on_cartquill_screens(): void {
		$assets = new AdminAssets();

		$assets->enqueue( 'toplevel_page_cartquill' );
		$assets->enqueue( 'cartquill_page_cartquill-flows' );
		$assets->enqueue( 'admin_page_cartquill-flow-builder' );

		$this->assertCount( 3, $this->enqueued );
		$this->assertSame( 'cartquill-admin', $this->enqueued[0][0], 'the style handle' );
		$this->assertStringContainsString( 'assets/admin/admin.css', $this->enqueued[0][1] );
	}

	public function test_leaves_other_admin_screens_alone(): void {
		$assets = new AdminAssets();

		$assets->enqueue( 'edit.php' );
		$assets->enqueue( 'plugins.php' );
		$assets->enqueue( 'woocommerce_page_wc-admin' );

		$this->assertSame( array(), $this->enqueued, 'no CartQuill styles outside CartQuill screens' );
	}
}
