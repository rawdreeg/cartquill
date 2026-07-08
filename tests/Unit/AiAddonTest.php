<?php
/**
 * The AI add-on boot: it only wires up when the AI plan is active, and it registers the
 * builder rewrite REST route + feature flag alongside the classic AI surfaces.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Ai\AiAddon;
use CartQuill\Flow\FlowLibrary;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\License;
use CartQuill\Licensing\OptionLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\InMemoryFlowRepository;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AiAddonTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Boot the add-on and capture the action + filter hooks it registers.
	 *
	 * @return array{actions: list<string>, filters: list<string>}
	 */
	private function boot( License $license ): array {
		$actions = array();
		$filters = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$actions ) {
				$actions[] = $hook;
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( $hook ) use ( &$filters ) {
				$filters[] = $hook;
			}
		);

		$addon = new AiAddon( new InMemoryFlowRepository(), new FlowLibrary(), new OptionLicense() );
		$addon->boot( $license );

		return array(
			'actions' => $actions,
			'filters' => $filters,
		);
	}

	public function test_registers_the_builder_rewrite_route_and_feature_when_licensed(): void {
		$hooks = $this->boot( new ArrayLicense( array( Plans::AI ) ) );

		$this->assertContains( 'rest_api_init', $hooks['actions'], 'the AI rewrite REST route is registered' );
		$this->assertContains( 'cartquill_builder_config', $hooks['filters'], 'the builder learns the AI rewrite feature is available' );
	}

	public function test_does_nothing_without_the_ai_plan(): void {
		$hooks = $this->boot( new ArrayLicense() );

		$this->assertSame( array(), $hooks['actions'], 'no hooks are registered for an unlicensed store' );
		$this->assertSame( array(), $hooks['filters'] );
	}
}
