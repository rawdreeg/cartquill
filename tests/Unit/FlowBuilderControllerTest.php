<?php
/**
 * The builder's REST read API: the catalog + stored flows, gated on capability.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Builder\BuilderCatalog;
use CartQuill\Builder\CoreActionDescriptors;
use CartQuill\Builder\CoreTriggers;
use CartQuill\Builder\FlowSerializer;
use CartQuill\Flow\FlowStep;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Rest\FlowBuilderController;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class FlowBuilderControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function controller( InMemoryFlowRepository $flows ): FlowBuilderController {
		$catalog = new BuilderCatalog( new ArrayLicense(), new InMemoryConnectionStore(), CoreActionDescriptors::all(), CoreTriggers::all() );
		return new FlowBuilderController( $flows, $catalog, new FlowSerializer() );
	}

	private function seeded(): InMemoryFlowRepository {
		$flows = new InMemoryFlowRepository();
		$flows->save( new FlowRecord( null, 'Welcome', 'welcome', FlowRecord::STATUS_ACTIVE, FlowRecord::SOURCE_TEMPLATE, array( new FlowStep( 0, 'Hi', 'Body' ) ) ) );
		$flows->save( new FlowRecord( null, 'Win back', 'win_back', FlowRecord::STATUS_DRAFT, FlowRecord::SOURCE_AI, array() ) );
		return $flows;
	}

	public function test_catalog_data_returns_the_three_sections(): void {
		$data = $this->controller( new InMemoryFlowRepository() )->catalog_data();

		$this->assertArrayHasKey( 'triggers', $data );
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'conditions', $data );
	}

	public function test_flow_list_summarizes_each_flow(): void {
		$list = $this->controller( $this->seeded() )->flow_list();

		$this->assertCount( 2, $list );
		$this->assertSame( 'Welcome', $list[0]['name'] );
		$this->assertSame( 1, $list[0]['step_count'] );
		$this->assertSame( 'draft', $list[1]['status'] );
	}

	public function test_flow_data_returns_the_serialized_flow(): void {
		$flows = $this->seeded();
		$data  = $this->controller( $flows )->flow_data( 1 );

		$this->assertSame( 'Welcome', $data['name'] );
		$this->assertSame( 'email', $data['steps'][0]['action'] );
	}

	public function test_flow_data_is_null_for_an_unknown_id(): void {
		$this->assertNull( $this->controller( new InMemoryFlowRepository() )->flow_data( 999 ) );
	}

	public function test_registers_three_get_routes_under_the_namespace(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$routes ) {
				$routes[] = array( $namespace, $route, $args );
				return true;
			}
		);

		$this->controller( new InMemoryFlowRepository() )->register_routes();

		$this->assertCount( 3, $routes );
		foreach ( $routes as $route ) {
			$this->assertSame( FlowBuilderController::NAMESPACE, $route[0] );
			$this->assertSame( 'GET', $route[2]['methods'] );
			$this->assertIsCallable( $route[2]['permission_callback'] );
		}
	}

	public function test_permission_requires_manage_options(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );

		$this->assertTrue( $this->controller( new InMemoryFlowRepository() )->can_manage() );
	}
}
