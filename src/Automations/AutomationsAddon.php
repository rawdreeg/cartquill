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
use CartQuill\Builder\ActionDescriptor;
use CartQuill\Builder\BuilderCatalog;
use CartQuill\Builder\TriggerDescriptor;
use CartQuill\Compliance\WpdbSuppressionList;
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
		private readonly TwilioClient $twilio,
	) {}

	public function register(): void {
		\add_action( 'cartquill_register_actions', array( $this, 'register_actions' ), 10, 2 );
		\add_action( 'cartquill_register_addons', array( $this, 'register_surfaces' ) );
		\add_filter( 'cartquill_flow_templates', array( $this, 'register_recipes' ) );

		// Contribute this add-on's actions + triggers to the flow builder's catalog.
		// Registered unconditionally (not license-gated) so a paid-but-unlicensed store
		// still sees them as locked "upgrade" cards; the catalog computes availability
		// from the license + connection status.
		\add_filter( BuilderCatalog::FILTER_ACTIONS, array( $this, 'builder_action_descriptors' ) );
		\add_filter( BuilderCatalog::FILTER_TRIGGERS, array( $this, 'builder_triggers' ) );
	}

	/**
	 * Replace the core static fallback descriptors for this add-on's actions with
	 * authoritative ones sourced from the live action classes, so a licensed store
	 * edits the exact config keys each action reads at runtime.
	 *
	 * @param list<ActionDescriptor> $descriptors The catalog's descriptors so far.
	 *
	 * @return list<ActionDescriptor>
	 */
	public function builder_action_descriptors( array $descriptors ): array {
		$authoritative = array(
			SlackAction::TYPE     => new ActionDescriptor( SlackAction::TYPE, 'Post to Slack', SlackAction::SERVICE, Plans::AUTOMATIONS, false, SlackAction::config_fields() ),
			SheetsAction::TYPE    => new ActionDescriptor( SheetsAction::TYPE, 'Append to Google Sheet', SheetsAction::SERVICE, Plans::AUTOMATIONS, false, SheetsAction::config_fields() ),
			MailchimpAction::TYPE => new ActionDescriptor( MailchimpAction::TYPE, 'Sync to Mailchimp', MailchimpAction::SERVICE, Plans::AUTOMATIONS, false, MailchimpAction::config_fields() ),
			SmsAction::TYPE       => new ActionDescriptor( SmsAction::TYPE, 'Send SMS', SmsAction::SERVICE, Plans::AUTOMATIONS, true, SmsAction::config_fields() ),
		);

		return array_map(
			static fn( ActionDescriptor $descriptor ) => $authoritative[ $descriptor->type ] ?? $descriptor,
			$descriptors
		);
	}

	/**
	 * Contribute this add-on's triggers, with the context keys each captures into the
	 * enrollment (offered to the builder's merge-tag picker and value gates). The
	 * types + context come from the trigger classes themselves, so the catalog cannot
	 * drift from what the triggers actually capture.
	 *
	 * @param list<TriggerDescriptor> $triggers The catalog's triggers so far.
	 *
	 * @return list<TriggerDescriptor>
	 */
	public function builder_triggers( array $triggers ): array {
		return array_merge(
			$triggers,
			array(
				new TriggerDescriptor( OrderPaidTrigger::TYPE, 'New paid order', 'An order was paid.', array( 'order_id', 'order_total' ), Plans::AUTOMATIONS ),
				new TriggerDescriptor( AccountCreatedTrigger::TYPE, 'New account created', 'A customer account was created.', array( 'marketing_opt_in' ), Plans::AUTOMATIONS ),
				new TriggerDescriptor( OrderShippedTrigger::TYPE, 'Order shipped', 'An order was marked shipped or completed.', array( 'phone', 'tracking_url' ), Plans::AUTOMATIONS ),
			)
		);
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
		if ( $this->is_connected( SmsAction::SERVICE ) ) {
			$actions->register( new SmsAction( $this->connections, $this->twilio, new Renderer() ) );
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
		( new MissingConnectionNotice( new WpdbFlowRepository(), $this->connections ) )->register();

		$type_enroller = new FlowTypeEnroller(
			new WpdbFlowRepository(),
			new Enroller( new WpdbEnrollmentRepository(), new ActionSchedulerScheduler(), new SystemClock() )
		);
		( new OrderPaidTrigger( $type_enroller ) )->register();
		( new AccountCreatedTrigger( $type_enroller ) )->register();
		( new OrderShippedTrigger( $type_enroller ) )->register();

		// Inbound Twilio STOP/START webhook, once a Twilio connection exists.
		$twilio = $this->connections->find( SmsAction::SERVICE );
		if ( null !== $twilio && '' !== (string) $twilio->credential( 'auth_token', '' ) ) {
			( new SmsWebhookEndpoint(
				new TwilioWebhookVerifier( (string) $twilio->credential( 'auth_token', '' ) ),
				new WpdbSuppressionList()
			) )->register();
		}
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
		$templates[] = AutomationsRecipes::shipping_update();

		return $templates;
	}
}
