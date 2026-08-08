<?php
/**
 * The round-trip proof: a flow authored through the builder's write path actually runs
 * its authored steps, in order, through the engine.
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
use CartQuill\Builder\FlowValidator;
use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Flow\Renderer;
use CartQuill\Builder\OpenAvailability;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Rest\FlowBuilderController;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Sender\FakeSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Proves the two halves of the builder epic connect: the write path
 * ({@see FlowBuilderController::create_flow()}, which the REST layer wraps) persists the
 * exact step JSON the engine reads, so a flow a user authors in the builder enrolls and
 * sends its steps in the authored order — the guarantee the builder is only useful if it
 * keeps.
 */
final class BuilderRoundTripTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const T0 = 1_700_000_000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_flow_authored_through_the_builder_runs_its_steps_in_order(): void {
		$flows = new InMemoryFlowRepository();

		// 1. Author a two-step email flow through the builder write path (as the REST POST does).
		$catalog    = new BuilderCatalog( new OpenAvailability(), new InMemoryConnectionStore(), CoreActionDescriptors::all(), CoreTriggers::all() );
		$controller = new FlowBuilderController(
			$flows,
			$catalog,
			new FlowSerializer(),
			new FlowValidator( $catalog )
		);

		$result = $controller->create_flow(
			array(
				'name'   => 'Welcome',
				'type'   => 'welcome',
				'status' => 'active',
				'steps'  => array(
					array( 'delay' => 0, 'action' => 'email', 'config' => array( 'subject' => 'Step 1', 'body' => 'first' ), 'conditions' => array() ),
					array( 'delay' => 3600, 'action' => 'email', 'config' => array( 'subject' => 'Step 2', 'body' => 'second' ), 'conditions' => array() ),
				),
			)
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '', $result['blocked'], 'nothing blocks a save' );

		$stored = $flows->find( (int) $result['flow']['id'] );
		$this->assertNotNull( $stored );
		$this->assertTrue( $stored->is_active() );
		$this->assertSame( FlowRecord::SOURCE_BUILDER, $stored->source );
		$this->assertCount( 2, $stored->steps );

		// 2. Wire the engine around the SAME repository the builder saved into.
		$enrollments = new InMemoryEnrollmentRepository();
		$sender      = new FakeSender();
		$scheduler   = new ArrayScheduler();
		$clock       = new FixedClock( self::T0 );
		$enroller    = new Enroller( $enrollments, $scheduler, $clock );
		$runner      = new StepRunner(
			$flows,
			$enrollments,
			new InMemoryMessageRepository(),
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$sender,
			new ArraySuppressionList(),
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$scheduler,
			$clock,
		);

		$tick = static fn() => $scheduler->run_due(
			$clock->now(),
			static fn( int $e, int $s ) => $runner->run_step( $e, $s )
		);

		// 3. Enroll a buyer and drain the schedule: the authored steps send in order.
		$enroller->enroll( $stored, 'buyer@example.com' );

		$tick();
		$this->assertSame( 1, $sender->count() );
		$this->assertSame( 'Step 1', $sender->last()->subject );

		$clock->advance( 3600 );
		$tick();
		$this->assertSame( 2, $sender->count() );
		$this->assertSame( 'Step 2', $sender->last()->subject );

		$subjects = array_map( static fn( $message ) => $message->subject, $sender->sent_messages() );
		$this->assertSame( array( 'Step 1', 'Step 2' ), $subjects, 'the builder-authored steps ran in their authored order' );
	}
}
