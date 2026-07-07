<?php
/**
 * The metadata the flow builder renders from: available triggers, actions, and
 * conditions, each tagged with whether the held plan + connections unlock it.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Licensing\License;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\ConnectionStore;

/**
 * There is no single machine-readable description of what a flow can trigger on,
 * do, or gate on — the knowledge is scattered across trigger classes, action
 * `execute()` bodies, and the condition evaluator. This catalog gathers it into
 * one JSON-serializable shape the REST layer hands to the builder, and stamps each
 * entry with a live `available` flag + `lock_reason` computed from the held plan
 * (via the {@see License} seam) and connection status (via {@see ConnectionStore}).
 *
 * It computes availability but never enforces it — {@see \CartQuill\Licensing\PlanGate}
 * and the write-side validator are the gate. The catalog only tells the UI what to
 * show as available versus locked-with-an-upgrade-path.
 */
final class BuilderCatalog {

	public const LOCK_PLAN              = 'requires_plan';
	public const LOCK_CONNECTION        = 'requires_connection';
	public const LOCK_CONDITIONAL_LOGIC = 'requires_conditional_logic';

	/**
	 * Filters an add-on hooks to contribute its authoritative action descriptors and
	 * its triggers, so paid metadata lives with the add-on and never has to be
	 * mirrored into this core module.
	 */
	public const FILTER_ACTIONS  = 'cartquill_builder_action_descriptors';
	public const FILTER_TRIGGERS = 'cartquill_builder_triggers';

	/**
	 * @param list<ActionDescriptor>  $actions  The action descriptors to offer.
	 * @param list<TriggerDescriptor> $triggers The trigger descriptors to offer.
	 */
	public function __construct(
		private readonly License $license,
		private readonly ConnectionStore $connections,
		private readonly array $actions,
		private readonly array $triggers,
	) {}

	/**
	 * @return array{triggers: list<array<string, mixed>>, actions: list<array<string, mixed>>, conditions: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'triggers'   => $this->triggers(),
			'actions'    => $this->actions(),
			'conditions' => $this->conditions(),
		);
	}

	/**
	 * The flow triggers, with the context keys each captures (for the merge-tag
	 * picker) and whether the held plan unlocks it.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function triggers(): array {
		$out = array();
		foreach ( $this->triggers as $trigger ) {
			$locked = null !== $trigger->capability && ! $this->license->is_active( $trigger->capability );

			$out[] = array(
				'type'         => $trigger->type,
				'label'        => $trigger->label,
				'description'  => $trigger->description,
				'context_keys' => array_values( array_unique( array_merge( $trigger->context_keys, array( 'customer_email' ) ) ) ),
				'available'    => ! $locked,
				'lock_reason'  => $locked ? self::LOCK_PLAN : '',
			);
		}
		return $out;
	}

	/**
	 * The step actions, with their editable fields and availability. A paid action
	 * is locked when its capability is not licensed (upgrade), then when its
	 * service is not connected (connect-first).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function actions(): array {
		$out = array();
		foreach ( $this->actions as $action ) {
			[ $available, $reason ] = $this->action_availability( $action );

			$out[] = array(
				'type'            => $action->type,
				'label'           => $action->label,
				'service'         => $action->service,
				'customer_facing' => $action->customer_facing,
				'fields'          => $action->fields,
				'available'       => $available,
				'lock_reason'     => $reason,
			);
		}
		return $out;
	}

	/**
	 * The step conditions, with their editable params and whether they are the paid
	 * conditional-logic feature (sourced from {@see ConditionEvaluator::GATES} so the
	 * flag cannot drift from what the engine actually meters).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function conditions(): array {
		$out = array();
		foreach ( self::CONDITIONS as $condition ) {
			$is_conditional_logic = in_array( $condition['type'], ConditionEvaluator::GATES, true );
			$locked               = $is_conditional_logic && ! $this->conditional_logic_enabled();

			$out[] = array(
				'type'              => $condition['type'],
				'label'             => $condition['label'],
				'params'            => $condition['params'],
				'conditional_logic' => $is_conditional_logic,
				'available'         => ! $locked,
				'lock_reason'       => $locked ? self::LOCK_CONDITIONAL_LOGIC : '',
			);
		}
		return $out;
	}

	/**
	 * @return array{0: bool, 1: string} [available, lock_reason]
	 */
	private function action_availability( ActionDescriptor $action ): array {
		if ( null === $action->capability ) {
			return array( true, '' );
		}
		if ( ! $this->license->is_active( $action->capability ) ) {
			return array( false, self::LOCK_PLAN );
		}
		if ( null !== $action->service && ! $this->is_connected( $action->service ) ) {
			return array( false, self::LOCK_CONNECTION );
		}
		return array( true, '' );
	}

	/**
	 * Whether a service has a healthy, configured connection — mirrors the gate the
	 * add-on applies before registering the action for the engine.
	 */
	private function is_connected( string $service ): bool {
		$connection = $this->connections->find( $service );
		return null !== $connection
			&& ConnectionRecord::STATUS_CONNECTED === $connection->status
			&& $connection->is_configured();
	}

	private function conditional_logic_enabled(): bool {
		return 0 !== (int) ( $this->license->limits()['conditional_logic'] ?? 0 );
	}

	/**
	 * The step conditions and their editable params. Whether each is the paid
	 * conditional-logic feature is derived from {@see ConditionEvaluator::GATES}, not
	 * stored here.
	 *
	 * @var list<array{type: string, label: string, params: list<array<string, mixed>>}>
	 */
	private const CONDITIONS = array(
		array(
			'type'   => 'exit_if_ordered',
			'label'  => 'Exit if the customer orders',
			'params' => array(
				array( 'key' => 'action', 'label' => 'Then', 'type' => 'select', 'options' => array( 'exit', 'skip' ), 'default' => 'exit', 'required' => false ),
			),
		),
		array(
			'type'   => 'require_context',
			'label'  => 'Require a value',
			'params' => array(
				array( 'key' => 'key', 'label' => 'Context key', 'type' => 'text', 'default' => '', 'required' => true ),
				array( 'key' => 'gt', 'label' => 'Greater than', 'type' => 'number', 'default' => '', 'required' => false ),
			),
		),
		array( 'type' => 'first_time_customer', 'label' => 'First-time customer', 'params' => array() ),
		array( 'type' => 'marketing_opt_in', 'label' => 'Marketing opt-in given', 'params' => array() ),
		array(
			'type'   => 'cart_value_gt',
			'label'  => 'Cart value greater than',
			'params' => array(
				array( 'key' => 'value', 'label' => 'Amount', 'type' => 'number', 'default' => '', 'required' => true ),
			),
		),
		array( 'type' => 'has_phone', 'label' => 'Has a phone number', 'params' => array() ),
	);
}
