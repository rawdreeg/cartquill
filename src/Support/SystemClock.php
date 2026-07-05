<?php
/**
 * Real time source.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class SystemClock implements Clock {

	public function now(): int {
		return time();
	}

	public function now_mysql(): string {
		return gmdate( 'Y-m-d H:i:s', $this->now() );
	}
}
