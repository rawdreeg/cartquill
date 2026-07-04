<?php
/**
 * In-memory scheduler that can be advanced in tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Scheduling;

/**
 * Records scheduled steps and replays the due ones on demand — the stand-in
 * for Action Scheduler in tests. `run_due()` keeps draining until nothing more
 * is due at the given time, so a chain of zero-delay steps completes in one
 * advance, just as Action Scheduler would across cron passes.
 */
final class ArrayScheduler implements Scheduler {

	/** @var list<array{timestamp: int, enrollment_id: int, step_index: int, done: bool}> */
	private array $jobs = array();

	public function schedule( int $timestamp, int $enrollment_id, int $step_index ): void {
		$this->jobs[] = array(
			'timestamp'     => $timestamp,
			'enrollment_id' => $enrollment_id,
			'step_index'    => $step_index,
			'done'          => false,
		);
	}

	/**
	 * Pending (not-yet-run) jobs.
	 *
	 * @return list<array{timestamp: int, enrollment_id: int, step_index: int}>
	 */
	public function pending(): array {
		$pending = array();
		foreach ( $this->jobs as $job ) {
			if ( ! $job['done'] ) {
				$pending[] = array(
					'timestamp'     => $job['timestamp'],
					'enrollment_id' => $job['enrollment_id'],
					'step_index'    => $job['step_index'],
				);
			}
		}
		return $pending;
	}

	/**
	 * Run every job due at or before $now, including any newly scheduled during
	 * this pass. Returns how many jobs ran.
	 *
	 * @param callable(int, int): void $runner Receives (enrollment_id, step_index).
	 */
	public function run_due( int $now, callable $runner ): int {
		$count = 0;
		do {
			$ran = false;
			foreach ( $this->jobs as $i => $job ) {
				if ( ! $job['done'] && $job['timestamp'] <= $now ) {
					$this->jobs[ $i ]['done'] = true;
					$runner( $job['enrollment_id'], $job['step_index'] );
					++$count;
					$ran = true;
				}
			}
		} while ( $ran );

		return $count;
	}
}
