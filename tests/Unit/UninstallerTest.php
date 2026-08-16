<?php
/**
 * Uninstall cleanup.
 *
 * The store's data survives deletion unless it explicitly opted in, and the
 * recurring background scans stop either way — leaving them scheduled would fire
 * actions for a plugin that is no longer installed.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Uninstaller;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class UninstallerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> Every query the fake $wpdb was asked to run. */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->queries = array();

		$wpdb = new class( $this->queries ) {
			public string $prefix  = 'wp_';
			public string $options = 'wp_options';

			/** @param list<string> $queries */
			public function __construct( public array &$queries ) {}

			public function query( string $sql ) {
				$this->queries[] = $sql;
				return true;
			}

			public function prepare( string $sql, ...$args ) {
				return $sql;
			}

			public function esc_like( string $text ): string {
				return $text;
			}
		};

		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_data_survives_when_the_store_did_not_opt_in(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		Uninstaller::run();

		$this->assertSame( array(), $GLOBALS['wpdb']->queries, 'nothing is dropped or deleted' );
	}

	public function test_data_survives_when_the_setting_is_absent_entirely(): void {
		Functions\when( 'get_option' )->justReturn( false );

		Uninstaller::run();

		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_opting_in_drops_every_table_and_clears_the_options(): void {
		Functions\when( 'get_option' )->justReturn( array( 'remove_data_on_uninstall' => true ) );

		Uninstaller::run();

		$queries = $GLOBALS['wpdb']->queries;
		foreach ( array( 'flows', 'enrollments', 'messages', 'attributions', 'settings', 'cart_captures' ) as $table ) {
			$this->assertContains(
				"DROP TABLE IF EXISTS `wp_cartquill_{$table}`",
				$queries,
				"the {$table} table is dropped"
			);
		}
		$this->assertStringContainsString(
			'DELETE FROM wp_options',
			end( $queries ),
			'options and transients are cleared last'
		);
	}

	/**
	 * Always, regardless of the data setting: an orphaned recurring action would
	 * keep firing for a plugin that is no longer there.
	 */
	public function test_the_background_scans_are_always_unscheduled(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$unscheduled = null;
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( $hook, $args, $group ) use ( &$unscheduled ) {
				$unscheduled = $group;
			}
		);

		Uninstaller::run();

		$this->assertSame( 'cartquill', $unscheduled );
	}
}
