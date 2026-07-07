<?php
/**
 * Abandoned-cart scanning: due carts enroll, fresh/recovered ones don't, and
 * enrollment is idempotent across scans.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Engine\Enroller;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Integration\AbandonedCartScanner;
use CartQuill\Persistence\CartCaptureRecord;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryCartCaptureStore;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Support\FixedClock;
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
		// Use the production default definition so step timing is real code.
		$this->flows->save( DefaultFlows::abandoned_cart( $status ) );
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

	public function test_cart_value_is_carried_into_the_enrollment_context(): void {
		$this->active_cart_flow();
		$this->captures->capture( 'buyer@example.com', $this->clock->now_mysql(), 75.0 );
		$this->clock->advance( self::THRESHOLD );

		$this->scanner->scan( self::THRESHOLD );

		$this->assertSame(
			75.0,
			$this->enrollments->all()[0]->context['cart_value'],
			'the captured cart total is available for value-based recovery gating'
		);
	}
}
