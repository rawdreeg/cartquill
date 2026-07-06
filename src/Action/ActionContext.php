<?php
/**
 * Everything an action needs to run one step for one enrollment.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Action;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Flow\FlowStep;

/**
 * The immutable input an {@see ActionInterface} receives: the step (its
 * templates + per-action `config`), the customer, the flow/step/enrollment
 * identifiers, the enrollment's trigger-time `context` (order total, phone,
 * opt-in, ...), and the claimed message row id used to tie open/click tracking
 * to this send.
 *
 * The runner builds it before claiming the message slot (so an action can
 * declare its {@see ActionInterface::target()} for the suppression check), then
 * stamps the claimed row id with {@see self::with_message_id()} before execute.
 */
final class ActionContext {

	/**
	 * @param FlowStep             $step           The step being run.
	 * @param string               $customer_email The enrolled customer's email.
	 * @param int                  $flow_id        Owning flow id.
	 * @param int                  $step_index     Step index within the flow.
	 * @param int|null             $enrollment_id  Owning enrollment id.
	 * @param array<string, mixed> $context        Trigger-time enrollment context.
	 * @param int                  $message_id     Claimed message row id (0 until claimed).
	 */
	public function __construct(
		public readonly FlowStep $step,
		public readonly string $customer_email,
		public readonly int $flow_id,
		public readonly int $step_index,
		public readonly ?int $enrollment_id,
		public readonly array $context = array(),
		public readonly int $message_id = 0,
	) {}

	/**
	 * A copy carrying the claimed message row id (set after the slot is reserved).
	 */
	public function with_message_id( int $message_id ): self {
		return new self(
			$this->step,
			$this->customer_email,
			$this->flow_id,
			$this->step_index,
			$this->enrollment_id,
			$this->context,
			$message_id,
		);
	}

	/**
	 * A value from the step's per-action config (Slack channel, spreadsheet id,
	 * SMS body, ...), or $default when unset.
	 */
	public function config( string $key, mixed $default = null ): mixed {
		return $this->step->config[ $key ] ?? $default;
	}

	/**
	 * A value from the enrollment's trigger-time context, or $default when unset.
	 */
	public function value( string $key, mixed $default = null ): mixed {
		return $this->context[ $key ] ?? $default;
	}
}
