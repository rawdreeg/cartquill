<?php
/**
 * The catalog factory folds add-on contributions over the core defaults.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Builder\BuilderCatalog;
use CartQuill\Builder\CatalogFactory;
use CartQuill\Builder\TriggerDescriptor;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\InMemoryConnectionStore;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class CatalogFactoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_builds_a_catalog_from_the_core_defaults(): void {
		// No add-on hooked: the filters pass the core lists through unchanged.
		Functions\when( 'apply_filters' )->alias( static fn( $hook, $value ) => $value );

		$catalog = CatalogFactory::create( new InMemoryConnectionStore() );

		$this->assertSame( array( 'email' ), array_column( $catalog->actions(), 'type' ), 'only the action the plugin ships' );
		$this->assertContains( 'abandoned_cart', array_column( $catalog->triggers(), 'type' ) );
	}

	public function test_folds_in_an_add_on_trigger_contribution(): void {
		// Simulate the Automations add-on contributing a trigger through the filter.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( BuilderCatalog::FILTER_TRIGGERS === $hook ) {
					$value[] = new TriggerDescriptor( 'order_alert', 'New paid order', 'An order was paid.', array( 'order_total' ), Plans::AUTOMATIONS );
				}
				return $value;
			}
		);

		$catalog = CatalogFactory::create( new InMemoryConnectionStore() );

		$triggers = array();
		foreach ( $catalog->triggers() as $trigger ) {
			$triggers[ $trigger['type'] ] = $trigger;
		}
		$this->assertArrayHasKey( 'order_alert', $triggers );
		$this->assertTrue( $triggers['order_alert']['available'], 'offered by default — nothing gates it' );
	}
}
