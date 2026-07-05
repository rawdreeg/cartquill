<?php
/**
 * One bounded, resumable page of lapsed-customer emails.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The result of a single win-back read: the lapsed emails found in this page,
 * an opaque cursor to resume from on the next tick, and whether the lookback
 * window has been fully scanned. The scanner persists {@see $next_offset}
 * between ticks and stops paging once {@see $done} is true.
 */
final class LapsedBatch {

	/**
	 * @param list<string> $emails      Distinct lapsed-customer emails in this page.
	 * @param int          $next_offset Cursor to pass back to resume after this page.
	 * @param bool         $done        True once the whole lookback window is scanned.
	 */
	public function __construct(
		public readonly array $emails,
		public readonly int $next_offset,
		public readonly bool $done,
	) {}
}
