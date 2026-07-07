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
use CartQuill\Flow\DefaultFlows;
use CartQuill\Flow\Renderer;
use CartQuill\Licensing\License;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\ConnectionStore;
use CartQuill\Persistence\FlowRecord;
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
		private readonly SheetsClient $sheets,
		private readonly MailchimpClient $mailchimp,
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

		// Gated on connection status: only a healthy (connected) connection
		// registers its action. An unconfigured or errored connection leaves the
		// action unavailable, so the step runner dead-letters and advances.
		if ( $this->is_connected( SlackAction::SERVICE ) ) {
			$actions->register( new SlackAction( $this->connections, $this->client, new Renderer() ) );
		}
		if ( $this->is_connected( SheetsAction::SERVICE ) ) {
			$actions->register( new SheetsAction( $this->connections, $this->sheets, new Renderer() ) );
		}
		if ( $this->is_connected( MailchimpAction::SERVICE ) ) {
			$actions->register( new MailchimpAction( $this->connections, $this->mailchimp ) );
		}
	}

	private function is_connected( string $service ): bool {
		$connection = $this->connections->find( $service );
		return null !== $connection
			&& ConnectionRecord::STATUS_CONNECTED === $connection->status
			&& $connection->is_configured();
	}

	public function register_surfaces(): void {
		if ( ! $this->license->is_active( Plans::AUTOMATIONS ) ) {
			return;
		}

		( new ConnectionsPage( $this->connections, $this->client, $this->sheets, $this->mailchimp ) )->register();

		$type_enroller = new FlowTypeEnroller(
			new WpdbFlowRepository(),
			new Enroller( new WpdbEnrollmentRepository(), new ActionSchedulerScheduler(), new SystemClock() )
		);
		( new OrderPaidTrigger( $type_enroller ) )->register();
		( new AccountCreatedTrigger( $type_enroller ) )->register();
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

		// Enhance the core abandoned-cart template in place with the Mailchimp
		// sync + value gate (same type, so the scanner still enrolls it and there
		// is no duplicate to install), then add the multi-tool recipes.
		$templates = array_map(
			static function ( $template ) {
				if ( $template instanceof FlowRecord && DefaultFlows::TYPE_ABANDONED_CART === $template->type ) {
					return AutomationsRecipes::cart_recovery();
				}
				return $template;
			},
			(array) $templates
		);

		$templates[] = AutomationsRecipes::order_alert();
		$templates[] = AutomationsRecipes::account_welcome();

		return $templates;
	}
}
