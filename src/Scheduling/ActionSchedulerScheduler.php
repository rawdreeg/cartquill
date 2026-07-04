<?php
/**
 * Runtime scheduler backed by Action Scheduler (bundled with WooCommerce).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Scheduling;

/**
 * Schedules each step as a single Action Scheduler action on the
 * `flowforge_run_step` hook, which the plugin wires to the StepRunner. Passing
 * the enrollment id and step index as unique-ish args, plus Action Scheduler's
 * own dedup, keeps duplicate scheduling cheap; the engine's message-level
 * idempotency is the real guard against double-sends.
 */
final class ActionSchedulerScheduler implements Scheduler {

	public const HOOK  = 'flowforge_run_step';
	public const GROUP = 'flowforge';

	public function schedule( int $timestamp, int $enrollment_id, int $step_index ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		\as_schedule_single_action(
			$timestamp,
			self::HOOK,
			array(
				'enrollment_id' => $enrollment_id,
				'step_index'    => $step_index,
			),
			self::GROUP
		);
	}
}
