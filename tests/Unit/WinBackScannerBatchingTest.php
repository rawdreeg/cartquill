<?php
/**
 * Win-back scanning is bounded per tick and resumes across ticks: a full page
 * parks a cursor to continue from; a final page clears it.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Engine\Enroller;
use CartQuill\Engine\LapsedBatch;
use CartQuill\Engine\LapsedCustomerFinder;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Integration\WinBackScanner;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\ArrayScanCursor;
use CartQuill\Tests\Fake\RecordingLapsedFinder;
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
