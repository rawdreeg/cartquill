<?php
/**
 * Admin notice for active flows that use a service with no working connection.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\ConnectionStore;
use CartQuill\Persistence\FlowRepository;

/**
 * The step runner already dead-letters and advances past a step whose service
 * isn't connected (rather than stalling the flow) — silently, from the store
 * owner's point of view. This surfaces that same condition as a heads-up: an
 * active flow references Slack/Sheets/Mailchimp/Twilio, but the connection is
 * missing or erroring, so those steps are being skipped right now.
 *
 * Silent when every service an active flow actually uses is connected, so this
 * never nags about services nothing currently depends on.
 */
final class MissingConnectionNotice {

	/** service => [action type, display label] */
	private const SERVICES = array(
		SlackAction::SERVICE     => array( SlackAction::TYPE, 'Slack' ),
		SheetsAction::SERVICE    => array( SheetsAction::TYPE, 'Google Sheets' ),
		MailchimpAction::SERVICE => array( MailchimpAction::TYPE, 'Mailchimp' ),
		SmsAction::SERVICE       => array( SmsAction::TYPE, 'Twilio SMS' ),
	);

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly ConnectionStore $connections,
	) {}

	public function register(): void {
		\add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$missing = $this->missing_services_in_use();
		if ( array() === $missing ) {
			return;
		}

		$connections_url = \admin_url( 'admin.php?page=' . ConnectionsPage::SLUG );
		$links           = array_map(
			static fn( string $label ) => sprintf(
				'<a href="%s">%s</a>',
				\esc_url( $connections_url ),
				\esc_html( $label )
			),
			$missing
		);

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			\wp_kses_post(
				sprintf(
					/* translators: %s: linked list of service names */
					\_n(
						'One of your active flows uses %s, which isn\'t connected — those steps are being skipped.',
						'Some of your active flows use %s, which aren\'t connected — those steps are being skipped.',
						count( $missing ),
						'cartquill'
					),
					implode( ', ', $links )
				)
			)
		);
	}

	/**
	 * Labels for services an active flow's steps reference but that aren't
	 * currently connected (unconfigured or erroring).
	 *
	 * @return list<string>
	 */
	private function missing_services_in_use(): array {
		$used = array();
		foreach ( $this->flows->all() as $flow ) {
			if ( ! $flow->is_active() ) {
				continue;
			}
			foreach ( $flow->steps as $step ) {
				foreach ( self::SERVICES as $service => $meta ) {
					if ( $meta[0] === $step->action ) {
						$used[ $service ] = $meta[1];
					}
				}
			}
		}

		$missing = array();
		foreach ( $used as $service => $label ) {
			if ( ! $this->is_connected( $service ) ) {
				$missing[] = $label;
			}
		}
		return $missing;
	}

	private function is_connected( string $service ): bool {
		$connection = $this->connections->find( $service );
		return null !== $connection
			&& ConnectionRecord::STATUS_CONNECTED === $connection->status
			&& $connection->is_configured();
	}
}
