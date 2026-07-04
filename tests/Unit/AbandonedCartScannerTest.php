<?php
/**
 * Abandoned-cart scanning: due carts enroll, fresh/recovered ones don't, and
 * enrollment is idempotent across scans.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Engine\Enroller;
use FlowForge\Flow\FlowStep;
use FlowForge\Integration\AbandonedCartScanner;
use FlowForge\Persistence\CartCaptureRecord;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryCartCaptureStore;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class AbandonedCartScannerTest extends TestCase {

	private const T0        = 1_700_000_000;
	private const THRESHOLD = 3600;

	private InMemoryCartCaptureStore $captures;
	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private FixedClock $clock;
	private AbandonedCartScanner $scanner;

	protected function setUp(): void {
		$this->captures    = new InMemoryCartCaptureStore();
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->clock       = new FixedClock( self::T0 );

		$enroller       = new Enroller( $this->enrollments, new ArrayScheduler(), $this->clock );
		$this->scanner  = new AbandonedCartScanner( $this->captures, $this->flows, $enroller, $this->clock );
	}

	private function active_cart_flow( string $status = FlowRecord::STATUS_ACTIVE ): void {
		$this->flows->save(
			new FlowRecord(
				id: null,
				name: 'Abandoned cart',
				type: AbandonedCartScanner::FLOW_TYPE,
				status: $status,
				source: FlowRecord::SOURCE_TEMPLATE,
				steps: array(
					new FlowStep( 3600, 't+1h', 'come back', array( array( 'type' => 'exit_if_ordered' ) ) ),
					new FlowStep( 86400, 't+24h', 'still?', array( array( 'type' => 'exit_if_ordered' ) ) ),
				),
			)
		);
	}

	/** Capture at T0, then let $seconds pass. */
	private function capture_then_wait( string $email, int $seconds ): void {
		$this->captures->capture( $email, $this->clock->now_mysql() );
		$this->clock->advance( $seconds );
	}

	public function test_idle_cart_past_threshold_is_enrolled(): void {
		$this->active_cart_flow();
		$this->capture_then_wait( 'buyer@example.com', self::THRESHOLD );

		$enrolled = $this->scanner->scan( self::THRESHOLD );

		$this->assertSame( 1, $enrolled );
		$this->assertCount( 1, $this->enrollments->all() );
		$this->assertSame(
			CartCaptureRecord::STATUS_ENROLLED,
			$this->captures->find( 'buyer@example.com' )->status
		);
	}

	public function test_fresh_cart_is_not_enrolled(): void {
		$this->active_cart_flow();
		$this->capture_then_wait( 'buyer@example.com', self::THRESHOLD - 60 ); // still within threshold

		$this->assertSame( 0, $this->scanner->scan( self::THRESHOLD ) );
		$this->assertCount( 0, $this->enrollments->all() );
	}

	public function test_recovered_cart_is_not_enrolled(): void {
		$this->active_cart_flow();
		$this->capture_then_wait( 'buyer@example.com', self::THRESHOLD );
		$this->captures->recover( 'buyer@example.com' );

		$this->assertSame( 0, $this->scanner->scan( self::THRESHOLD ) );
	}

	public function test_no_active_flow_leaves_capture_pending(): void {
		$this->active_cart_flow( FlowRecord::STATUS_DRAFT );
		$this->capture_then_wait( 'buyer@example.com', self::THRESHOLD );

		$this->assertSame( 0, $this->scanner->scan( self::THRESHOLD ) );
		$this->assertSame(
			CartCaptureRecord::STATUS_PENDING,
			$this->captures->find( 'buyer@example.com' )->status,
			'left pending so it enrolls once a template is activated'
		);
	}

	public function test_second_scan_does_not_re_enroll(): void {
		$this->active_cart_flow();
		$this->capture_then_wait( 'buyer@example.com', self::THRESHOLD );

		$this->scanner->scan( self::THRESHOLD );
		$this->assertSame( 0, $this->scanner->scan( self::THRESHOLD ) );
		$this->assertCount( 1, $this->enrollments->all() );
	}
}
