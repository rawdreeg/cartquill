<?php
/**
 * Time source, injectable so tests are deterministic.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Support;

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
