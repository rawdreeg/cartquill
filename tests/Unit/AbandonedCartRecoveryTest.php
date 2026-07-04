<?php
/**
 * Full abandoned-cart path through the FakeSender seam: capture -> scan ->
 * enroll -> nudge at t+1h -> recovery order -> exit before t+24h.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Compliance\ArraySuppressionList;
use FlowForge\Engine\ConditionEvaluator;
use FlowForge\Engine\Enroller;
use FlowForge\Engine\MessageComposer;
use FlowForge\Engine\StepRunner;
use FlowForge\Flow\DefaultFlows;
use FlowForge\Flow\Renderer;
use FlowForge\Integration\AbandonedCartScanner;
use FlowForge\Persistence\EnrollmentRecord;
use FlowForge\Persistence\InMemoryCartCaptureStore;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Sender\FakeSender;
use FlowForge\Settings\ArraySettings;
use FlowForge\Support\FixedClock;
use FlowForge\Tests\Fake\FakeCustomerActivity;
use PHPUnit\Framework\TestCase;

final class AbandonedCartRecoveryTest extends TestCase {

	private const T0        = 1_700_000_000;
	private const THRESHOLD = 3600;

	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryCartCaptureStore $captures;
	private FakeSender $sender;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private FakeCustomerActivity $activity;
	private AbandonedCartScanner $scanner;
	private StepRunner $runner;

	protected function setUp(): void {
		$flows             = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$messages          = new InMemoryMessageRepository();
		$this->captures    = new InMemoryCartCaptureStore();
		$this->sender      = new FakeSender();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( self::T0 );
		$this->activity    = new FakeCustomerActivity();

		$flows->save( DefaultFlows::abandoned_cart( \FlowForge\Persistence\FlowRecord::STATUS_ACTIVE ) );

		$enroller      = new Enroller( $this->enrollments, $this->scheduler, $this->clock );
		$this->scanner = new AbandonedCartScanner( $this->captures, $flows, $enroller, $this->clock );
		$this->runner  = new StepRunner(
			$flows,
			$this->enrollments,
			$messages,
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

	public function test_abandoned_cart_nudges_then_exits_on_recovery(): void {
		// Cart captured, then goes idle past the abandonment threshold.
		$this->captures->capture( 'buyer@example.com', $this->clock->now_mysql() );
		$this->clock->advance( self::THRESHOLD );

		$this->scanner->scan( self::THRESHOLD );
		$enroll_time = $this->clock->now();

		// First nudge is scheduled t+1h after enrollment.
		$pending = $this->scheduler->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( $enroll_time + 3600, $pending[0]['timestamp'] );

		// t+1h: first nudge sends; the t+24h follow-up is scheduled.
		$this->clock->advance( 3600 );
		$this->tick();
		$this->assertSame( 1, $this->sender->count() );
		$this->assertSame( 'You left something behind', $this->sender->last()->subject );
		$pending = $this->scheduler->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( $enroll_time + 3600 + 86400, $pending[0]['timestamp'], 't+24h follow-up' );

		// Customer recovers: places an order after enrolling.
		$this->activity->record_order( 'buyer@example.com', $enroll_time + 100 );

		// t+24h: the follow-up evaluates exit_if_ordered and stops — no send.
		$this->clock->advance( 86400 );
		$this->tick();
		$this->assertSame( 1, $this->sender->count(), 'no follow-up after the customer converts' );
		$this->assertSame( EnrollmentRecord::STATUS_EXITED, $this->enrollments->all()[0]->status );
	}
}
