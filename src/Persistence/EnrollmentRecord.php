<?php
/**
 * A row in the `flow_enrollments` table: one customer's run through a flow.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

/**
 * Mirrors the `flow_enrollments` table. The spine creates an `active`
 * enrollment at step 0; the engine slice adds progression (current_step,
 * next_run_at) and the terminal states (completed/exited/unsubscribed).
 */
final class EnrollmentRecord {

	public const STATUS_ACTIVE       = 'active';
	public const STATUS_COMPLETED    = 'completed';
	public const STATUS_EXITED       = 'exited';
	public const STATUS_UNSUBSCRIBED = 'unsubscribed';

	/**
	 * @param int|null    $id             Row id (null until persisted).
	 * @param int         $flow_id        Owning flow.
	 * @param string      $customer_email Enrolled customer's email.
	 * @param string      $status         One of the STATUS_* constants.
	 * @param int         $current_step   Index of the next step to run.
	 * @param string|null $created_at     MySQL datetime (set by the DB default).
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $flow_id,
		public readonly string $customer_email,
		public readonly string $status = self::STATUS_ACTIVE,
		public readonly int $current_step = 0,
		public readonly ?string $created_at = null,
	) {}

	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->flow_id,
			$this->customer_email,
			$this->status,
			$this->current_step,
			$this->created_at,
		);
	}
}
