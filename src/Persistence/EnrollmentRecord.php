<?php
/**
 * A row in the `flow_enrollments` table: one customer's run through a flow.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Mirrors the `flow_enrollments` table. An enrollment advances through a flow's
 * steps: `current_step` is the index of the next step to run and `next_run_at`
 * is when it is due. Terminal states stop further sends.
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
	 * @param string|null $next_run_at    MySQL datetime the next step is due.
	 * @param string|null $created_at     MySQL datetime the enrollment began.
	 * @param string      $source         Consent/trigger source (e.g. "newsletter",
	 *                                     "first_order", "post_purchase", "abandoned_cart").
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $flow_id,
		public readonly string $customer_email,
		public readonly string $status = self::STATUS_ACTIVE,
		public readonly int $current_step = 0,
		public readonly ?string $next_run_at = null,
		public readonly ?string $created_at = null,
		public readonly string $source = '',
	) {}

	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	public function with_id( int $id ): self {
		return $this->copy( array( 'id' => $id ) );
	}

	public function with_status( string $status ): self {
		return $this->copy( array( 'status' => $status ) );
	}

	/**
	 * Advance to a step, recording when it is next due (null once terminal).
	 */
	public function with_progress( int $current_step, ?string $next_run_at ): self {
		return $this->copy(
			array(
				'current_step' => $current_step,
				'next_run_at'  => $next_run_at,
			)
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function copy( array $overrides ): self {
		return new self(
			$overrides['id'] ?? $this->id,
			$overrides['flow_id'] ?? $this->flow_id,
			$overrides['customer_email'] ?? $this->customer_email,
			$overrides['status'] ?? $this->status,
			$overrides['current_step'] ?? $this->current_step,
			array_key_exists( 'next_run_at', $overrides ) ? $overrides['next_run_at'] : $this->next_run_at,
			$overrides['created_at'] ?? $this->created_at,
			$overrides['source'] ?? $this->source,
		);
	}
}
