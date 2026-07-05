<?php
/**
 * Time source, injectable so tests are deterministic.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

interface Clock {

	/**
	 * Current time as a Unix timestamp (UTC).
	 */
	public function now(): int;

	/**
	 * Current time as a MySQL datetime string (UTC).
	 */
	public function now_mysql(): string;
}
