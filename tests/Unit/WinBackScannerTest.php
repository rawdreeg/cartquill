<?php
/**
 * Win-back scanning: only lapsed customers enroll, once per flow, and a
 * recent order keeps a customer out.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Engine\Enroller;
use FlowForge\Flow\DefaultFlows;
use FlowForge\Integration\WinBackScanner;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Support\FixedClock;
use FlowForge\Tests\Fake\FakeOrderHistory;
use PHPUnit\Framework\TestCase;

final class WinBackScannerTest extends TestCase {

	private const NOW       = 1_700_000_000;
	private const THRESHOLD = 7776000; // 90 days
	private const DAY       = 86400;

	private FakeOrderHistory $orders;
	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private WinBackScanner $scanner;

	protected function setUp(): void {
		$this->orders      = new FakeOrderHistory();
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();

		$enroller      = new Enroller( $this->enrollments, new ArrayScheduler(), new FixedClock( self::NOW ) );
		$this->scanner = new WinBackScanner( $this->orders, $this->flows, $this->enrollments, $enroller, new FixedClock( self::NOW ) );
	}

	private function active_win_back(): void {
		$this->flows->save( DefaultFlows::win_back( FlowRecord::STATUS_ACTIVE ) );
	}

	private function enrolled_emails(): array {
		return array_map( static fn( $e ) => $e->customer_email, $this->enrollments->all() );
	}

	public function test_only_lapsed_customers_are_enrolled(): void {
		$this->active_win_back();
		$this->orders->set_last_order( 'lapsed@example.com', self::NOW - self::THRESHOLD - self::DAY ); // 91 days ago
		$this->orders->set_last_order( 'recent@example.com', self::NOW - ( 10 * self::DAY ) );          // 10 days ago
		$this->orders->set_last_order( 'edge@example.com', self::NOW - self::THRESHOLD + 60 );          // just under threshold

		$enrolled = $this->scanner->scan( self::THRESHOLD );

		$this->assertSame( 1, $enrolled );
		$this->assertSame( array( 'lapsed@example.com' ), $this->enrolled_emails() );
	}

	public function test_no_active_flow_enrolls_nobody(): void {
		$this->flows->save( DefaultFlows::win_back( FlowRecord::STATUS_DRAFT ) );
		$this->orders->set_last_order( 'lapsed@example.com', self::NOW - self::THRESHOLD - self::DAY );

		$this->assertSame( 0, $this->scanner->scan( self::THRESHOLD ) );
	}

	public function test_customer_is_won_back_only_once_per_flow(): void {
		$this->active_win_back();
		$this->orders->set_last_order( 'lapsed@example.com', self::NOW - self::THRESHOLD - self::DAY );

		$this->scanner->scan( self::THRESHOLD );
		// Even after the enrollment completes, a second scan does not re-enroll.
		$second = $this->scanner->scan( self::THRESHOLD );

		$this->assertSame( 0, $second );
		$this->assertCount( 1, $this->enrollments->all() );
	}

	public function test_customer_with_no_orders_is_skipped(): void {
		$this->active_win_back();
		// customer_emails() only lists those with a recorded order, so an empty
		// history simply yields nothing.
		$this->assertSame( 0, $this->scanner->scan( self::THRESHOLD ) );
	}
}
