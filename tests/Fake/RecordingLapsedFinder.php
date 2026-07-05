<?php
/**
 * LapsedCustomerFinder that returns a scripted batch and records its paging args.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Fake;

use FlowForge\Engine\LapsedBatch;
use FlowForge\Engine\LapsedCustomerFinder;

/**
 * Returns a fixed {@see LapsedBatch} and remembers the limit/offset it was
 * called with, so the scanner's cursor handling can be asserted in isolation.
 */
final class RecordingLapsedFinder implements LapsedCustomerFinder {

	public int $received_limit  = -1;
	public int $received_offset = -1;

	public function __construct( private readonly LapsedBatch $batch ) {}

	public function lapsed_before( int $cutoff, int $limit = 0, int $offset = 0 ): LapsedBatch {
		$this->received_limit  = $limit;
		$this->received_offset = $offset;
		return $this->batch;
	}
}
