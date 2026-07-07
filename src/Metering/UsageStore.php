<?php
/**
 * Persistence seam for per-month usage counts.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Metering;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Stores one executed-action count per period (month). Keeps the meter free of
 * $wpdb so it can be tested against an in-memory implementation; the wpdb-backed
 * implementation increments with an O(1) upsert (never a scan over messages).
 */
interface UsageStore {

	public function count( string $period ): int;

	/**
	 * Record one executed action in $period (insert-or-increment).
	 */
	public function increment( string $period ): void;
}
