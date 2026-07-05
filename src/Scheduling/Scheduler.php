<?php
/**
 * Queue seam: schedules a flow step to run at a future time.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Scheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Abstracts the queue so the engine never calls Action Scheduler directly.
 * Runtime uses Action Scheduler (bundled with WooCommerce); tests use an array
 * scheduler that can be advanced deterministically. Per the locked decision,
 * we never hand-roll cron.
 */
interface Scheduler {

	/**
	 * Schedule step $step_index of $enrollment_id to run at $timestamp (Unix, UTC).
	 */
	public function schedule( int $timestamp, int $enrollment_id, int $step_index ): void;
}
