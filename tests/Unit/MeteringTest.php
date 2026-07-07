<?php
/**
 * Usage metering: the meter's accounting, the engine's run-to-cap-then-defer
 * enforcement, and the enrollment block at the cap.
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
use CartQuill\Flow\FlowStep;
use CartQuill\Flow\Renderer;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Metering\InMemoryUsageStore;
use CartQuill\Metering\UsageMeter;
use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Sender\FakeSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use PHPUnit\Framework\TestCase;

final class MeteringTest extends TestCase {

	private const T0 = 1_700_000_000; // 2023-11-14 UTC

	private function meter( InMemoryUsageStore $store, int $cap, FixedClock $clock ): UsageMeter {
		return new UsageMeter( $store, new ArrayLicense( array(), array( 'actions' => $cap ) ), $clock );
	}

	public function test_meter_accounting_and_month_reset(): void {
		$store = new InMemoryUsageStore();
		$clock = new FixedClock( self::T0 );
		$meter = $this->meter( $store, 2, $clock );

		$this->assertSame( 0, $meter->current() );
		$this->assertSame( 2, $meter->limit() );
		$this->assertSame( 2, $meter->remaining() );
		$this->assertFalse( $meter->would_exceed() );

		$meter->increment();
		$this->assertSame( 1, $meter->current() );
		$this->assertSame( 1, $meter->remaining() );
		$this->assertFalse( $meter->would_exceed() );

		$meter->increment();
		$this->assertSame( 2, $meter->current() );
		$this->assertSame( 0, $meter->remaining() );
		$this->assertTrue( $meter->would_exceed(), 'at the cap, one more would exceed' );

		// The count resets at the month boundary.
		$clock->advance( 2592000 );
		$this->assertSame( 0, $meter->current(), 'a new month starts fresh' );
		$this->assertFalse( $meter->would_exceed() );
	}

	public function test_actions_run_to_the_cap_then_defer_without_dropping_the_customer(): void {
		$flows       = new InMemoryFlowRepository();
		$enrollments = new InMemoryEnrollmentRepository();
		$messages    = new InMemoryMessageRepository();
		$sender      = new FakeSender();
		$scheduler   = new ArrayScheduler();
		$clock       = new FixedClock( self::T0 );
		$store       = new InMemoryUsageStore();
		$meter       = $this->meter( $store, 2, $clock );

		$runner = new StepRunner(
			$flows,
			$enrollments,
			$messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$sender,
			new ArraySuppressionList(),
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$scheduler,
			$clock,
			null,
			$meter,
		);

		// Three back-to-back steps; the cap is 2.
		$flow = $flows->save(
			new FlowRecord(
				null,
				'Three',
				'post_purchase',
				FlowRecord::STATUS_ACTIVE,
				FlowRecord::SOURCE_TEMPLATE,
				array( new FlowStep( 0, 'A', 'a' ), new FlowStep( 0, 'B', 'b' ), new FlowStep( 0, 'C', 'c' ) )
			)
		);
		( new Enroller( $enrollments, $scheduler, $clock ) )->enroll( $flow, 'buyer@example.com' );

		$scheduler->run_due( $clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );

		// Exactly the cap executed; the third step deferred, not dropped.
		$this->assertSame( 2, $sender->count(), 'no billable action beyond the cap' );
		$this->assertSame( 2, $meter->current(), 'counted once per executed action' );
		$enrollment = $enrollments->all()[0];
		$this->assertSame( EnrollmentRecord::STATUS_ACTIVE, $enrollment->status, 'the customer is not dropped' );
		$this->assertSame( 2, $enrollment->current_step, 'parked on the over-cap step' );

		$pending = $scheduler->pending();
		$this->assertCount( 1, $pending, 'the over-cap step is rescheduled' );
		$this->assertGreaterThan( $clock->now(), $pending[0]['timestamp'], 'deferred to a future period' );

		// Next month the cap resets and the parked step runs.
		$clock->advance( 3456000 );
		$scheduler->run_due( $clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );

		$this->assertSame( 3, $sender->count(), 'the deferred action runs once the cap resets' );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $enrollments->all()[0]->status );
	}

	public function test_enrollment_is_blocked_at_the_cap(): void {
		$enrollments = new InMemoryEnrollmentRepository();
		$scheduler   = new ArrayScheduler();
		$clock       = new FixedClock( self::T0 );
		$store       = new InMemoryUsageStore();
		$store->increment( gmdate( 'Y-m', self::T0 ) ); // usage now at the cap of 1
		$meter = $this->meter( $store, 1, $clock );

		$flow = ( new InMemoryFlowRepository() )->save(
			new FlowRecord( null, 'W', 'welcome', FlowRecord::STATUS_ACTIVE, FlowRecord::SOURCE_TEMPLATE, array( new FlowStep( 0, 'Hi', 'body' ) ) )
		);

		$result = ( new Enroller( $enrollments, $scheduler, $clock, $meter ) )->enroll( $flow, 'buyer@example.com' );

		$this->assertNull( $result, 'no new enrollment once the cap is reached' );
		$this->assertCount( 0, $enrollments->all() );
	}
}
