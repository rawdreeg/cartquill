<?php
/**
 * Drives the win-back flow through the engine (FakeSender): a lapsed customer
 * is nudged, then exits when they order again.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Flow\Renderer;
use CartQuill\Integration\WinBackScanner;
use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Sender\FakeSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\ArrayScanCursor;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use CartQuill\Tests\Fake\FakeLapsedCustomerFinder;
use PHPUnit\Framework\TestCase;

final class WinBackFlowTest extends TestCase {

	private const NOW       = 1_700_000_000;
	private const THRESHOLD = 7776000;

	private InMemoryEnrollmentRepository $enrollments;
	private FakeSender $sender;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private FakeCustomerActivity $activity;
	private WinBackScanner $scanner;
	private StepRunner $runner;

	protected function setUp(): void {
		$flows             = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->sender      = new FakeSender();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( self::NOW );
		$this->activity    = new FakeCustomerActivity();

		$flows->save( DefaultFlows::win_back( FlowRecord::STATUS_ACTIVE ) );

		$orders = new FakeLapsedCustomerFinder();
		$orders->set_last_order( 'lapsed@example.com', self::NOW - self::THRESHOLD - 86400 );

		$enroller      = new Enroller( $this->enrollments, $this->scheduler, $this->clock );
		$this->scanner = new WinBackScanner( $orders, $flows, $this->enrollments, $enroller, $this->clock, new ArrayScanCursor() );
		$this->runner  = new StepRunner(
			$flows,
			$this->enrollments,
			new InMemoryMessageRepository(),
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$this->sender,
			new ArraySuppressionList(),
			new ConditionEvaluator( $this->activity ),
			$this->scheduler,
			$this->clock,
		);
	}

	private function tick(): void {
		$this->scheduler->run_due(
			$this->clock->now(),
			fn( int $e, int $s ) => $this->runner->run_step( $e, $s )
		);
	}

	public function test_lapsed_customer_is_nudged_then_exits_on_new_order(): void {
		$this->scanner->scan( self::THRESHOLD );

		// First nudge sends immediately.
		$this->tick();
		$this->assertSame( 1, $this->sender->count() );
		$this->assertSame( 'We miss you at Acme', $this->sender->last()->subject );

		// The t+7d follow-up is actually scheduled (not merely absent later).
		$pending = $this->scheduler->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( 1, $pending[0]['step_index'] );
		$this->assertSame( self::NOW + 604800, $pending[0]['timestamp'] );

		// The customer comes back and orders.
		$this->activity->record_order( 'lapsed@example.com', self::NOW + 100 );

		// The t+7d follow-up evaluates exit_if_ordered and stops.
		$this->clock->advance( 604800 );
		$this->tick();
		$this->assertSame( 1, $this->sender->count(), 'no follow-up after winning them back' );
		$this->assertSame( EnrollmentRecord::STATUS_EXITED, $this->enrollments->all()[0]->status );
	}
}
