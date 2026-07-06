<?php
/**
 * The multi-tool automation add-on: connections + integration actions.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Action\ActionRegistry;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\FlowTypeEnroller;
use CartQuill\Flow\Renderer;
use CartQuill\Licensing\License;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\ConnectionStore;
use CartQuill\Persistence\WpdbEnrollmentRepository;
use CartQuill\Persistence\WpdbFlowRepository;
use CartQuill\Scheduling\ActionSchedulerScheduler;
use CartQuill\Support\SystemClock;

/**
 * Wires itself in only when the Automations plan is active. Registers the Slack
 * action through the `cartquill_register_actions` seam (so the engine is
 * untouched), exposes the Connections admin screen, installs the order-paid
 * trigger, and contributes its recipes to the flow library.
 *
 * Actions are also gated on connection status: the Slack action is only
 * registered once a Slack connection is configured, so an unconfigured service
 * leaves the action unavailable and the step runner dead-letters and advances
 * rather than stalling.
 */
final class AutomationsAddon {

	public function __construct(
		private readonly ConnectionStore $connections,
		private readonly License $license,
		private readonly SlackClient $client,
	) {}

	public function register(): void {
		\add_action( 'cartquill_register_actions', array( $this, 'register_actions' ), 10, 2 );
		\add_action( 'cartquill_register_addons', array( $this, 'register_surfaces' ) );
		\add_filter( 'cartquill_flow_templates', array( $this, 'register_recipes' ) );
	}

	/**
	 * @param ActionRegistry $actions The action registry.
	 * @param License        $license The licensing gate.
	 */
	public function register_actions( ActionRegistry $actions, License $license ): void {
		if ( ! $license->is_active( Plans::AUTOMATIONS ) ) {
			return;
		}

		// Gated on connection status: only a healthy (connected) Slack connection
		// registers the action. An unconfigured or errored connection leaves the
		// action unavailable, so the step runner dead-letters and advances.
		$slack = $this->connections->find( SlackAction::SERVICE );
		if ( null !== $slack && ConnectionRecord::STATUS_CONNECTED === $slack->status && $slack->is_configured() ) {
			$actions->register( new SlackAction( $this->connections, $this->client, new Renderer() ) );
		}
	}

	public function register_surfaces(): void {
		if ( ! $this->license->is_active( Plans::AUTOMATIONS ) ) {
			return;
		}

		( new ConnectionsPage( $this->connections, $this->client ) )->register();

		$enroller = new Enroller(
			new WpdbEnrollmentRepository(),
			new ActionSchedulerScheduler(),
			new SystemClock()
		);
		( new OrderPaidTrigger( new FlowTypeEnroller( new WpdbFlowRepository(), $enroller ) ) )->register();
	}

	/**
	 * Contribute the add-on's recipes to the installable flow library.
	 *
	 * @param mixed $templates The templates collected so far.
	 *
	 * @return array<int, mixed>
	 */
	public function register_recipes( $templates ): array {
		if ( ! $this->license->is_active( Plans::AUTOMATIONS ) ) {
			return (array) $templates;
		}
		$templates   = (array) $templates;
		$templates[] = AutomationsRecipes::order_alert();
		return $templates;
	}
}
