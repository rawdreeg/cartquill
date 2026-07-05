<?php
/**
 * Enrollment creation and idempotency (a customer is never enrolled twice in
 * the same flow run).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Engine\Enroller;
use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class EnrollerTest extends TestCase {

	private InMemoryEnrollmentRepository $enrollments;
	private ArrayScheduler $scheduler;
	private Enroller $enroller;

	protected function setUp(): void {
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->scheduler   = new ArrayScheduler();
		$this->enroller    = new Enroller( $this->enrollments, $this->scheduler, new FixedClock( 1_700_000_000 ) );
	}

	private function flow( string $status = FlowRecord::STATUS_ACTIVE, array $steps = array() ): FlowRecord {
		if ( array() === $steps ) {
			$steps = array( new FlowStep( 0, 'Hi', 'body' ) );
		}
		return new FlowRecord( 7, 'Test', 'welcome', $status, FlowRecord::SOURCE_TEMPLATE, $steps );
	}

	public function test_enroll_creates_an_active_enrollment(): void {
		$enrollment = $this->enroller->enroll( $this->flow(), 'buyer@example.com' );

		$this->assertNotNull( $enrollment );
		$this->assertSame( 7, $enrollment->flow_id );
		$this->assertTrue( $enrollment->is_active() );
		$this->assertCount( 1, $this->enrollments->all() );
		$this->assertCount( 1, $this->scheduler->pending() );
	}

	public function test_enrolling_twice_is_a_no_op(): void {
		$first  = $this->enroller->enroll( $this->flow(), 'buyer@example.com' );
		$second = $this->enroller->enroll( $this->flow(), 'buyer@example.com' );

		$this->assertNotNull( $first );
		$this->assertNull( $second, 'already-active customer is not enrolled again' );
		$this->assertCount( 1, $this->enrollments->all() );
		$this->assertCount( 1, $this->scheduler->pending() );
	}

	public function test_inactive_flow_does_not_enroll(): void {
		$this->assertNull( $this->enroller->enroll( $this->flow( FlowRecord::STATUS_DRAFT ), 'buyer@example.com' ) );
		$this->assertCount( 0, $this->enrollments->all() );
	}

	public function test_flow_without_steps_does_not_enroll(): void {
		$flow = new FlowRecord( 7, 'Empty', 'welcome', FlowRecord::STATUS_ACTIVE, FlowRecord::SOURCE_TEMPLATE, array() );
		$this->assertNull( $this->enroller->enroll( $flow, 'buyer@example.com' ) );
	}
}
