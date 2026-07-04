<?php
/**
 * End-to-end engine behavior through the FakeSender + ArrayScheduler seams:
 * scheduling, delays, multi-step progression, conditions/exit-on-conversion,
 * suppression, and idempotency.
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
use FlowForge\Flow\FlowStep;
use FlowForge\Flow\Renderer;
use FlowForge\Persistence\EnrollmentRecord;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Sender\FakeSender;
use FlowForge\Settings\ArraySettings;
use FlowForge\Support\FixedClock;
use FlowForge\Tests\Fake\FakeCustomerActivity;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase {

	private const T0 = 1_700_000_000;

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryMessageRepository $messages;
	private FakeSender $sender;
	private ArraySuppressionList $suppression;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private FakeCustomerActivity $activity;
	private Enroller $enroller;
	private StepRunner $runner;

	protected function setUp(): void {
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->messages    = new InMemoryMessageRepository();
		$this->sender      = new FakeSender();
		$this->suppression = new ArraySuppressionList();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( self::T0 );
		$this->activity    = new FakeCustomerActivity();

		$this->enroller = new Enroller( $this->enrollments, $this->scheduler, $this->clock );
		$this->runner   = new StepRunner(
			$this->flows,
			$this->enrollments,
			$this->messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$this->sender,
			$this->suppression,
			new ConditionEvaluator( $this->activity ),
			$this->scheduler,
			$this->clock,
		);
	}

	/**
	 * @param list<FlowStep> $steps
	 */
	private function active_flow( array $steps ): FlowRecord {
		return $this->flows->save(
			new FlowRecord(
				id: null,
				name: 'Test',
				type: 'post_purchase',
				status: FlowRecord::STATUS_ACTIVE,
				source: FlowRecord::SOURCE_TEMPLATE,
				steps: $steps,
			)
		);
	}

	/** Run every step due at the current clock time. */
	private function tick(): int {
		return $this->scheduler->run_due(
			$this->clock->now(),
			fn( int $e, int $s ) => $this->runner->run_step( $e, $s )
		);
	}

	public function test_enroll_schedules_the_first_step(): void {
		$flow = $this->active_flow( array( new FlowStep( 0, 'Hi', 'body' ) ) );

		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$pending = $this->scheduler->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( self::T0, $pending[0]['timestamp'] );
		$this->assertSame( 0, $pending[0]['step_index'] );
		$this->assertSame( 0, $this->sender->count(), 'nothing sends until the step runs' );
	}

	public function test_multi_step_flow_sends_each_step_at_its_delay(): void {
		$flow = $this->active_flow(
			array(
				new FlowStep( 0, 'Step 1', 'first' ),
				new FlowStep( 3600, 'Step 2', 'second' ),
			)
		);
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		// t0: first step fires; second is scheduled an hour out.
		$this->tick();
		$this->assertSame( 1, $this->sender->count() );
		$pending = $this->scheduler->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( self::T0 + 3600, $pending[0]['timestamp'] );
		$this->assertSame( 1, $pending[0]['step_index'] );

		// Still an hour early: nothing new fires.
		$this->tick();
		$this->assertSame( 1, $this->sender->count() );

		// Advance an hour: second step fires and the flow completes.
		$this->clock->advance( 3600 );
		$this->tick();
		$this->assertSame( 2, $this->sender->count() );
		$this->assertSame( 'Step 2', $this->sender->last()->subject );

		$enrollment = $this->enrollments->all()[0];
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $enrollment->status );
		$this->assertSame( array(), $this->scheduler->pending() );
	}

	public function test_exit_on_conversion_stops_later_steps(): void {
		$flow = $this->active_flow(
			array(
				new FlowStep( 0, 'Reminder 1', 'come back' ),
				new FlowStep( 3600, 'Reminder 2', 'still here', array( array( 'type' => 'exit_if_ordered' ) ) ),
			)
		);
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$this->tick(); // first reminder sent
		$this->assertSame( 1, $this->sender->count() );

		// Customer purchases after enrolling.
		$this->activity->record_order( 'buyer@example.com', self::T0 + 10 );

		$this->clock->advance( 3600 );
		$this->tick(); // second step evaluates the condition -> exit

		$this->assertSame( 1, $this->sender->count(), 'no second reminder after conversion' );
		$this->assertSame( EnrollmentRecord::STATUS_EXITED, $this->enrollments->all()[0]->status );
	}

	public function test_suppressed_address_is_never_sent(): void {
		$this->suppression->suppress( 'buyer@example.com', 'unsubscribe' );
		$flow = $this->active_flow( array( new FlowStep( 0, 'Hi', 'body' ) ) );
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$this->tick();

		$this->assertSame( 0, $this->sender->count() );
		$this->assertSame( EnrollmentRecord::STATUS_UNSUBSCRIBED, $this->enrollments->all()[0]->status );
	}

	public function test_step_is_never_sent_twice(): void {
		$flow       = $this->active_flow( array( new FlowStep( 0, 'Hi', 'body' ) ) );
		$enrollment = $this->enroller->enroll( $flow, 'buyer@example.com' );
		$id         = (int) $enrollment->id;

		$this->runner->run_step( $id, 0 );
		$this->runner->run_step( $id, 0 ); // forced duplicate

		$this->assertSame( 1, $this->sender->count() );
		$this->assertTrue( $this->messages->exists_for_step( $id, 0 ) );
		$this->assertSame(
			MessageRecord::STATUS_SENT,
			$this->messages->all()[0]->status
		);
	}

	public function test_a_pre_claimed_slot_blocks_the_send(): void {
		// Simulate a concurrent worker that already reserved (enrollment, step 0).
		$flow       = $this->active_flow( array( new FlowStep( 0, 'Hi', 'body' ) ) );
		$enrollment = $this->enroller->enroll( $flow, 'buyer@example.com' );
		$id         = (int) $enrollment->id;

		$this->messages->claim(
			new MessageRecord( null, $id, (int) $flow->id, 0, 'buyer@example.com', 'other-worker', MessageRecord::STATUS_QUEUED )
		);

		$this->runner->run_step( $id, 0 );

		$this->assertSame( 0, $this->sender->count(), 'claim lost to the other worker; no send' );
	}

	public function test_suppression_wins_over_conversion_ordering(): void {
		// A customer who both unsubscribed and converted terminates as
		// UNSUBSCRIBED, because suppression is checked first (locked order).
		$this->suppression->suppress( 'buyer@example.com' );
		$this->activity->record_order( 'buyer@example.com', self::T0 + 10 );

		$flow = $this->active_flow(
			array( new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'exit_if_ordered' ) ) ) )
		);
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$this->tick();

		$this->assertSame( 0, $this->sender->count() );
		$this->assertSame( EnrollmentRecord::STATUS_UNSUBSCRIBED, $this->enrollments->all()[0]->status );
	}
}
