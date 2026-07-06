<?php
/**
 * The flow library and one-click install.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Flow\FlowInstaller;
use CartQuill\Flow\FlowLibrary;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryFlowRepository;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class FlowLibraryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		Monkey\setUp();
		// templates() runs the cartquill_flow_templates filter; pass it through so
		// the core set is returned unchanged (no add-on contributing recipes here).
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_library_offers_the_four_core_templates(): void {
		$types = array_map( static fn( $t ) => $t->type, ( new FlowLibrary() )->templates() );

		$this->assertSame(
			array(
				DefaultFlows::TYPE_ABANDONED_CART,
				DefaultFlows::TYPE_WELCOME,
				DefaultFlows::TYPE_POST_PURCHASE,
				DefaultFlows::TYPE_WIN_BACK,
			),
			$types
		);
	}

	public function test_get_returns_a_template_by_type(): void {
		$library = new FlowLibrary();
		$this->assertSame( 'Welcome', $library->get( DefaultFlows::TYPE_WELCOME )->name );
		$this->assertNull( $library->get( 'made_up' ) );
	}

	public function test_install_creates_an_editable_draft_flow_with_the_template_steps(): void {
		$flows     = new InMemoryFlowRepository();
		$installer = new FlowInstaller( new FlowLibrary(), $flows );

		$flow = $installer->install( DefaultFlows::TYPE_WELCOME );

		$this->assertNotNull( $flow->id );
		$this->assertSame( FlowRecord::STATUS_DRAFT, $flow->status );
		$this->assertCount( 2, $flow->steps );
		$this->assertSame( 'Welcome to {{ store_name }}', $flow->steps[0]->subject );
		// It is a real, retrievable row.
		$this->assertNotNull( $flows->find( (int) $flow->id ) );
	}

	public function test_installing_an_unknown_type_is_a_no_op(): void {
		$flows     = new InMemoryFlowRepository();
		$installer = new FlowInstaller( new FlowLibrary(), $flows );

		$this->assertNull( $installer->install( 'nope' ) );
		$this->assertCount( 0, $flows->all() );
	}
}
