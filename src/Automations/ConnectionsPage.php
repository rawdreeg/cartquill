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

	public function handle_test(): void {
		$this->authorize( 'cartquill_test_connection' );

		$service = isset( $_POST['service'] ) ? \sanitize_key( \wp_unslash( $_POST['service'] ) ) : '';
		$ok      = match ( $service ) {
			SlackAction::SERVICE  => $this->test_slack(),
			SheetsAction::SERVICE => $this->test_sheets(),
			default               => false,
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

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = isset( $_GET['cartquill_notice'] ) ? \sanitize_text_field( \wp_unslash( $_GET['cartquill_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'Connections', 'cartquill' ); ?></h1>
			<?php $this->render_notice( $notice ); ?>

			<p><?php echo \esc_html__( 'Connect your own external services. Credentials are stored encrypted and used only from your site.', 'cartquill' ); ?></p>

			<?php
			$this->render_slack_card();
			$this->render_sheets_card();
			?>
		</div>
		<?php
	}

	private function render_slack_card(): void {
		$connection = $this->connections->find( SlackAction::SERVICE );
		$configured = null !== $connection && $connection->is_configured();
		?>
		<h2><?php echo \esc_html__( 'Slack', 'cartquill' ); ?>
			<?php echo $configured ? '<span class="description">' . \esc_html( $this->status_label( (string) $connection->status ) ) . '</span>' : ''; ?>
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
		<?php
	}

	private function render_sheets_card(): void {
		$connection = $this->connections->find( SheetsAction::SERVICE );
		$configured = null !== $connection && $connection->is_configured();
		$has_sa     = null !== $connection && '' !== (string) $connection->credential( 'service_account', '' );
		?>
		<hr />
		<h2><?php echo \esc_html__( 'Google Sheets', 'cartquill' ); ?>
			<?php echo $configured ? '<span class="description">' . \esc_html( $this->status_label( (string) $connection->status ) ) . '</span>' : ''; ?>
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
