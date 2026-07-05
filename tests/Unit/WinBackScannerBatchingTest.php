<?php
/**
 * Win-back scanning is bounded per tick and resumes across ticks: a full page
 * parks a cursor to continue from; a final page clears it.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Engine\Enroller;
use FlowForge\Engine\LapsedBatch;
use FlowForge\Engine\LapsedCustomerFinder;
use FlowForge\Flow\DefaultFlows;
use FlowForge\Integration\WinBackScanner;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Support\FixedClock;
use FlowForge\Tests\Fake\ArrayScanCursor;
use FlowForge\Tests\Fake\RecordingLapsedFinder;
use PHPUnit\Framework\TestCase;

final class WinBackScannerBatchingTest extends TestCase {

	private const NOW       = 1_700_000_000;
	private const THRESHOLD = 7776000;

	private function scanner( LapsedCustomerFinder $finder, ArrayScanCursor $cursor ): WinBackScanner {
		$flows = new InMemoryFlowRepository();
		$flows->save( DefaultFlows::win_back( FlowRecord::STATUS_ACTIVE ) );
		$enrollments = new InMemoryEnrollmentRepository();
		$enroller    = new Enroller( $enrollments, new ArrayScheduler(), new FixedClock( self::NOW ) );
		return new WinBackScanner( $finder, $flows, $enrollments, $enroller, new FixedClock( self::NOW ), $cursor );
	}

	public function test_full_page_parks_cursor_for_the_next_tick(): void {
		$finder = new RecordingLapsedFinder(
			new LapsedBatch( array( 'a@example.com', 'b@example.com' ), 200, false )
		);
		$cursor = new ArrayScanCursor();

		$enrolled = $this->scanner( $finder, $cursor )->scan( self::THRESHOLD );

		$this->assertSame( 2, $enrolled );
		$this->assertSame( 0, $finder->received_offset, 'starts from the parked cursor (0)' );
		$this->assertSame( WinBackScanner::BATCH_SIZE, $finder->received_limit );
		$this->assertSame( 200, $cursor->get(), 'parks the next offset' );
		$this->assertFalse( $cursor->was_cleared() );
	}

	public function test_final_page_resumes_then_clears_the_cursor(): void {
		$finder = new RecordingLapsedFinder(
			new LapsedBatch( array( 'c@example.com' ), 250, true )
		);
		$cursor = new ArrayScanCursor( 200 );

		$enrolled = $this->scanner( $finder, $cursor )->scan( self::THRESHOLD );

		$this->assertSame( 1, $enrolled );
		$this->assertSame( 200, $finder->received_offset, 'resumes from the stored cursor' );
		$this->assertTrue( $cursor->was_cleared(), 'clears once the window is drained' );
	}
}
