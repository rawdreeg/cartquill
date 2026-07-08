<?php
/**
 * Connections admin screen: connect external services (Slack, Google Sheets, …).
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

/**
 * Lets the store connect its own external services. Credentials are stored
 * encrypted (via the connection store) and shown masked and write-only — an
 * existing secret is displayed as a mask and only overwritten when a new value
 * is submitted. "Test connection" sends a probe and records the result as the
 * connection's status. The save/test handlers dispatch on a hidden `service`
 * field so each card owns its own form.
 */
final class ConnectionsPage {

	private const PARENT = 'cartquill';
	public const SLUG    = 'cartquill-connections';
	private const MASK   = '••••••••';

	public function __construct(
		private readonly ConnectionStore $connections,
		private readonly SlackClient $slack,
		private readonly SheetsClient $sheets,
		private readonly MailchimpClient $mailchimp,
	) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_post_cartquill_save_connection', array( $this, 'handle_save' ) );
		\add_action( 'admin_post_cartquill_test_connection', array( $this, 'handle_test' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			self::PARENT,
			\__( 'Connections', 'cartquill' ),
			\__( 'Connections', 'cartquill' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_save(): void {
		$this->authorize( 'cartquill_save_connection' );

		$service = isset( $_POST['service'] ) ? \sanitize_key( \wp_unslash( $_POST['service'] ) ) : '';
		if ( SlackAction::SERVICE === $service ) {
			$this->save_slack();
		} elseif ( SheetsAction::SERVICE === $service ) {
			$this->save_sheets();
		} elseif ( MailchimpAction::SERVICE === $service ) {
			$this->save_mailchimp();
		} elseif ( SmsAction::SERVICE === $service ) {
			$this->save_twilio();
		}

		$this->redirect_back( 'saved' );
	}

	private function save_slack(): void {
		$webhook = isset( $_POST['slack_webhook'] ) ? \sanitize_text_field( \wp_unslash( $_POST['slack_webhook'] ) ) : '';
		if ( '' === $webhook || self::MASK === $webhook ) {
			return; // Keep the existing (masked) value.
		}

		$existing                   = $this->connections->find( SlackAction::SERVICE );
		$credentials                = null !== $existing ? $existing->credentials : array();
		$credentials['webhook_url'] = \esc_url_raw( $webhook );

		$this->connections->save(
			new ConnectionRecord( $existing?->id, SlackAction::SERVICE, ConnectionRecord::STATUS_CONNECTED, $credentials )
		);
	}

	private function save_sheets(): void {
		$existing    = $this->connections->find( SheetsAction::SERVICE );
		$credentials = null !== $existing ? $existing->credentials : array();

		// The service-account JSON is multi-line; do not run it through
		// sanitize_text_field (which would collapse it). Validate it parses,
		// and only overwrite when a real (non-masked) value is submitted.
		$raw_sa = isset( $_POST['sheets_service_account'] ) ? trim( (string) \wp_unslash( $_POST['sheets_service_account'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $raw_sa && self::MASK !== $raw_sa && is_array( json_decode( $raw_sa, true ) ) ) {
			$credentials['service_account'] = $raw_sa;
		}
		if ( isset( $_POST['sheets_spreadsheet_id'] ) ) {
			$credentials['spreadsheet_id'] = \sanitize_text_field( \wp_unslash( $_POST['sheets_spreadsheet_id'] ) );
		}
		if ( isset( $_POST['sheets_range'] ) ) {
			$credentials['range'] = \sanitize_text_field( \wp_unslash( $_POST['sheets_range'] ) );
		}

		$this->connections->save(
			new ConnectionRecord( $existing?->id, SheetsAction::SERVICE, ConnectionRecord::STATUS_CONNECTED, $credentials )
		);
	}

	private function save_mailchimp(): void {
		$existing    = $this->connections->find( MailchimpAction::SERVICE );
		$credentials = null !== $existing ? $existing->credentials : array();

		$key = isset( $_POST['mailchimp_api_key'] ) ? \sanitize_text_field( \wp_unslash( $_POST['mailchimp_api_key'] ) ) : '';
		if ( '' !== $key && self::MASK !== $key ) {
			$credentials['api_key']       = $key;
			$credentials['server_prefix'] = $this->derive_prefix( $key );
		}
		if ( isset( $_POST['mailchimp_audience_id'] ) ) {
			$credentials['audience_id'] = \sanitize_text_field( \wp_unslash( $_POST['mailchimp_audience_id'] ) );
		}

		$this->connections->save(
			new ConnectionRecord( $existing?->id, MailchimpAction::SERVICE, ConnectionRecord::STATUS_CONNECTED, $credentials )
		);
	}

	/**
	 * The Mailchimp data-center prefix is the suffix of the API key (e.g. "us21"
	 * in "abc...-us21").
	 */
	private function derive_prefix( string $key ): string {
		$pos = strrpos( $key, '-' );
		return false !== $pos ? substr( $key, $pos + 1 ) : '';
	}

	private function save_twilio(): void {
		$existing    = $this->connections->find( SmsAction::SERVICE );
		$credentials = null !== $existing ? $existing->credentials : array();

		if ( isset( $_POST['twilio_account_sid'] ) ) {
			$credentials['account_sid'] = \sanitize_text_field( \wp_unslash( $_POST['twilio_account_sid'] ) );
		}
		if ( isset( $_POST['twilio_from_number'] ) ) {
			$credentials['from_number'] = \sanitize_text_field( \wp_unslash( $_POST['twilio_from_number'] ) );
		}
		$token = isset( $_POST['twilio_auth_token'] ) ? \sanitize_text_field( \wp_unslash( $_POST['twilio_auth_token'] ) ) : '';
		if ( '' !== $token && self::MASK !== $token ) {
			$credentials['auth_token'] = $token;
		}

		$this->connections->save(
			new ConnectionRecord( $existing?->id, SmsAction::SERVICE, ConnectionRecord::STATUS_CONNECTED, $credentials )
		);
	}

	public function handle_test(): void {
		$this->authorize( 'cartquill_test_connection' );

		$service = isset( $_POST['service'] ) ? \sanitize_key( \wp_unslash( $_POST['service'] ) ) : '';
		$ok      = match ( $service ) {
			SlackAction::SERVICE     => $this->test_slack(),
			SheetsAction::SERVICE    => $this->test_sheets(),
			MailchimpAction::SERVICE => $this->test_mailchimp(),
			default                  => false,
		};

		$this->redirect_back( $ok ? 'test_ok' : 'test_error' );
	}

	private function test_slack(): bool {
		$connection = $this->connections->find( SlackAction::SERVICE );
		$webhook    = null !== $connection ? (string) $connection->credential( 'webhook_url', '' ) : '';
		if ( '' === $webhook ) {
			return false;
		}

		$result = $this->slack->post( $webhook, '', \__( 'CartQuill test message — your Slack connection is working.', 'cartquill' ) );
		$this->connections->save( $connection->with_status( $result->ok ? ConnectionRecord::STATUS_CONNECTED : ConnectionRecord::STATUS_ERROR ) );

		return $result->ok;
	}

	private function test_sheets(): bool {
		$connection = $this->connections->find( SheetsAction::SERVICE );
		if ( null === $connection ) {
			return false;
		}

		$service_account = json_decode( (string) $connection->credential( 'service_account', '' ), true );
		$spreadsheet_id  = (string) $connection->credential( 'spreadsheet_id', '' );
		$range           = (string) $connection->credential( 'range', 'Sheet1' );
		if ( ! is_array( $service_account ) || '' === $spreadsheet_id ) {
			return false;
		}

		$result = $this->sheets->append(
			$service_account,
			$spreadsheet_id,
			$range,
			array( \__( 'CartQuill test row', 'cartquill' ), \current_time( 'mysql', true ) )
		);
		$this->connections->save( $connection->with_status( $result->ok ? ConnectionRecord::STATUS_CONNECTED : ConnectionRecord::STATUS_ERROR ) );

		return $result->ok;
	}

	private function test_mailchimp(): bool {
		$connection = $this->connections->find( MailchimpAction::SERVICE );
		if ( null === $connection ) {
			return false;
		}

		$credentials = array(
			'api_key'       => (string) $connection->credential( 'api_key', '' ),
			'server_prefix' => (string) $connection->credential( 'server_prefix', '' ),
			'audience_id'   => (string) $connection->credential( 'audience_id', '' ),
		);
		if ( '' === $credentials['api_key'] || '' === $credentials['server_prefix'] || '' === $credentials['audience_id'] ) {
			return false;
		}

		$result = $this->mailchimp->sync( $credentials, (string) \get_option( 'admin_email' ), 'cartquill-test' );
		$this->connections->save( $connection->with_status( $result->ok ? ConnectionRecord::STATUS_CONNECTED : ConnectionRecord::STATUS_ERROR ) );

		return $result->ok;
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = isset( $_GET['cartquill_notice'] ) ? \sanitize_text_field( \wp_unslash( $_GET['cartquill_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap cartquill-admin">
			<h1><?php echo \esc_html__( 'Connections', 'cartquill' ); ?></h1>
			<?php $this->render_notice( $notice ); ?>

			<p class="cartquill-onboard__lead"><?php echo \esc_html__( 'Connect your own external services. Credentials are stored encrypted and used only from your site.', 'cartquill' ); ?></p>

			<?php
			$this->render_slack_card();
			$this->render_sheets_card();
			$this->render_mailchimp_card();
			$this->render_twilio_card();
			?>
		</div>
		<?php
	}

	private function render_slack_card(): void {
		$connection = $this->connections->find( SlackAction::SERVICE );
		$configured = null !== $connection && $connection->is_configured();
		?>
		<div class="cartquill-panel">
		<h2><?php echo \esc_html__( 'Slack', 'cartquill' ); ?>
			<?php echo $this->status_badge( $connection ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a constant class + esc_html'd label. ?>
		</h2>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<?php \wp_nonce_field( 'cartquill_save_connection' ); ?>
			<input type="hidden" name="action" value="cartquill_save_connection" />
			<input type="hidden" name="service" value="<?php echo \esc_attr( SlackAction::SERVICE ); ?>" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="cq-slack-webhook"><?php echo \esc_html__( 'Slack incoming webhook URL', 'cartquill' ); ?></label></th>
					<td>
						<input type="text" id="cq-slack-webhook" name="slack_webhook" class="regular-text" autocomplete="off"
							value="<?php echo $configured ? \esc_attr( self::MASK ) : ''; ?>"
							placeholder="https://hooks.slack.com/services/..." />
						<p class="description"><?php echo \esc_html__( 'Create an incoming webhook in your Slack workspace and paste its URL here.', 'cartquill' ); ?></p>
					</td>
				</tr>
			</table>
			<?php \submit_button( \__( 'Save Slack connection', 'cartquill' ) ); ?>
		</form>
		<?php $this->render_test_button( SlackAction::SERVICE, $configured ); ?>
		</div>
		<?php
	}

	private function render_sheets_card(): void {
		$connection = $this->connections->find( SheetsAction::SERVICE );
		$configured = null !== $connection && $connection->is_configured();
		$has_sa     = null !== $connection && '' !== (string) $connection->credential( 'service_account', '' );
		?>
		<div class="cartquill-panel">
		<h2><?php echo \esc_html__( 'Google Sheets', 'cartquill' ); ?>
			<?php echo $this->status_badge( $connection ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a constant class + esc_html'd label. ?>
		</h2>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<?php \wp_nonce_field( 'cartquill_save_connection' ); ?>
			<input type="hidden" name="action" value="cartquill_save_connection" />
			<input type="hidden" name="service" value="<?php echo \esc_attr( SheetsAction::SERVICE ); ?>" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="cq-sheets-sa"><?php echo \esc_html__( 'Service account JSON', 'cartquill' ); ?></label></th>
					<td>
						<textarea id="cq-sheets-sa" name="sheets_service_account" rows="4" class="large-text code" autocomplete="off" placeholder='{ "client_email": "...", "private_key": "..." }'><?php echo $has_sa ? \esc_textarea( self::MASK ) : ''; ?></textarea>
						<p class="description"><?php echo \esc_html__( 'Paste a Google service-account JSON key, then share your sheet with the account\'s email address as an Editor.', 'cartquill' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cq-sheets-id"><?php echo \esc_html__( 'Spreadsheet ID', 'cartquill' ); ?></label></th>
					<td><input type="text" id="cq-sheets-id" name="sheets_spreadsheet_id" class="regular-text"
						value="<?php echo null !== $connection ? \esc_attr( (string) $connection->credential( 'spreadsheet_id', '' ) ) : ''; ?>"
						placeholder="1AbC..." /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cq-sheets-range"><?php echo \esc_html__( 'Target range', 'cartquill' ); ?></label></th>
					<td><input type="text" id="cq-sheets-range" name="sheets_range" class="regular-text"
						value="<?php echo null !== $connection ? \esc_attr( (string) $connection->credential( 'range', 'Sheet1' ) ) : 'Sheet1'; ?>"
						placeholder="Sheet1" /></td>
				</tr>
			</table>
			<?php \submit_button( \__( 'Save Google Sheets connection', 'cartquill' ) ); ?>
		</form>
		<?php $this->render_test_button( SheetsAction::SERVICE, $configured ); ?>
		</div>
		<?php
	}

	private function render_mailchimp_card(): void {
		$connection = $this->connections->find( MailchimpAction::SERVICE );
		$configured = null !== $connection && $connection->is_configured();
		$has_key    = null !== $connection && '' !== (string) $connection->credential( 'api_key', '' );
		?>
		<div class="cartquill-panel">
		<h2><?php echo \esc_html__( 'Mailchimp', 'cartquill' ); ?>
			<?php echo $this->status_badge( $connection ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a constant class + esc_html'd label. ?>
		</h2>
		<p class="description"><?php echo \esc_html__( 'CartQuill syncs contacts and tags into your Mailchimp audience — it does not send your email through Mailchimp. Your recovery and welcome emails still send from your own store.', 'cartquill' ); ?></p>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<?php \wp_nonce_field( 'cartquill_save_connection' ); ?>
			<input type="hidden" name="action" value="cartquill_save_connection" />
			<input type="hidden" name="service" value="<?php echo \esc_attr( MailchimpAction::SERVICE ); ?>" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="cq-mc-key"><?php echo \esc_html__( 'API key', 'cartquill' ); ?></label></th>
					<td>
						<input type="text" id="cq-mc-key" name="mailchimp_api_key" class="regular-text" autocomplete="off"
							value="<?php echo $has_key ? \esc_attr( self::MASK ) : ''; ?>"
							placeholder="abc123...-us21" />
						<p class="description"><?php echo \esc_html__( 'The data-center suffix (e.g. us21) is read from the key automatically.', 'cartquill' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cq-mc-audience"><?php echo \esc_html__( 'Audience ID', 'cartquill' ); ?></label></th>
					<td><input type="text" id="cq-mc-audience" name="mailchimp_audience_id" class="regular-text"
						value="<?php echo null !== $connection ? \esc_attr( (string) $connection->credential( 'audience_id', '' ) ) : ''; ?>"
						placeholder="a1b2c3d4e5" /></td>
				</tr>
			</table>
			<?php \submit_button( \__( 'Save Mailchimp connection', 'cartquill' ) ); ?>
		</form>
		<?php $this->render_test_button( MailchimpAction::SERVICE, $configured ); ?>
		</div>
		<?php
	}

	private function render_twilio_card(): void {
		$connection = $this->connections->find( SmsAction::SERVICE );
		$has_token  = null !== $connection && '' !== (string) $connection->credential( 'auth_token', '' );
		?>
		<div class="cartquill-panel">
		<h2><?php echo \esc_html__( 'Twilio SMS', 'cartquill' ); ?>
			<?php echo $this->status_badge( $connection ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a constant class + esc_html'd label. ?>
		</h2>
		<p class="description"><?php echo \esc_html__( 'Text customers from your own Twilio account. Point a Twilio Messaging webhook at the URL below to honour STOP/START replies:', 'cartquill' ); ?>
			<br /><code><?php echo \esc_html( \add_query_arg( SmsWebhookEndpoint::PARAM, 'twilio', \home_url( '/' ) ) ); ?></code></p>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<?php \wp_nonce_field( 'cartquill_save_connection' ); ?>
			<input type="hidden" name="action" value="cartquill_save_connection" />
			<input type="hidden" name="service" value="<?php echo \esc_attr( SmsAction::SERVICE ); ?>" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="cq-tw-sid"><?php echo \esc_html__( 'Account SID', 'cartquill' ); ?></label></th>
					<td><input type="text" id="cq-tw-sid" name="twilio_account_sid" class="regular-text"
						value="<?php echo null !== $connection ? \esc_attr( (string) $connection->credential( 'account_sid', '' ) ) : ''; ?>"
						placeholder="AC..." /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cq-tw-token"><?php echo \esc_html__( 'Auth token', 'cartquill' ); ?></label></th>
					<td><input type="text" id="cq-tw-token" name="twilio_auth_token" class="regular-text" autocomplete="off"
						value="<?php echo $has_token ? \esc_attr( self::MASK ) : ''; ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cq-tw-from"><?php echo \esc_html__( 'From number', 'cartquill' ); ?></label></th>
					<td><input type="text" id="cq-tw-from" name="twilio_from_number" class="regular-text"
						value="<?php echo null !== $connection ? \esc_attr( (string) $connection->credential( 'from_number', '' ) ) : ''; ?>"
						placeholder="+15551234567" /></td>
				</tr>
			</table>
			<?php \submit_button( \__( 'Save Twilio connection', 'cartquill' ) ); ?>
		</form>
		</div>
		<?php
	}

	private function render_test_button( string $service, bool $configured ): void {
		if ( ! $configured ) {
			return;
		}
		?>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
			<?php \wp_nonce_field( 'cartquill_test_connection' ); ?>
			<input type="hidden" name="action" value="cartquill_test_connection" />
			<input type="hidden" name="service" value="<?php echo \esc_attr( $service ); ?>" />
			<button type="submit" class="button"><?php echo \esc_html__( 'Test connection', 'cartquill' ); ?></button>
		</form>
		<?php
	}

	private function status_label( string $status ): string {
		return match ( $status ) {
			ConnectionRecord::STATUS_CONNECTED => '✓ ' . \__( 'Connected', 'cartquill' ),
			ConnectionRecord::STATUS_ERROR     => '✗ ' . \__( 'Connection error', 'cartquill' ),
			default                            => \__( 'Not configured', 'cartquill' ),
		};
	}

	/**
	 * A colored status pill for a connection header, or '' when the service is
	 * not configured yet.
	 */
	private function status_badge( ?ConnectionRecord $connection ): string {
		if ( null === $connection || ! $connection->is_configured() ) {
			return '';
		}
		$status  = (string) $connection->status;
		$variant = match ( $status ) {
			ConnectionRecord::STATUS_CONNECTED => ' cartquill-badge--active',
			ConnectionRecord::STATUS_ERROR     => ' cartquill-badge--paused',
			default                            => '',
		};
		return '<span class="cartquill-badge' . $variant . '">' . \esc_html( $this->status_label( $status ) ) . '</span>';
	}

	private function render_notice( string $notice ): void {
		$map = array(
			'saved'      => array( 'success', \__( 'Connection saved.', 'cartquill' ) ),
			'test_ok'    => array( 'success', \__( 'Test succeeded — check the service.', 'cartquill' ) ),
			'test_error' => array( 'error', \__( 'Could not reach the service. Check the credentials and try again.', 'cartquill' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			\esc_attr( $map[ $notice ][0] ),
			\esc_html( $map[ $notice ][1] )
		);
	}

	private function authorize( string $nonce_action ): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( $nonce_action ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'cartquill' ) );
		}
	}

	private function redirect_back( string $notice ): never {
		\wp_safe_redirect(
			\add_query_arg(
				array(
					'page'             => self::SLUG,
					'cartquill_notice' => $notice,
				),
				\admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
