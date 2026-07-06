<?php
/**
 * Connections admin screen: connect external services (Slack, …).
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
 * connection's status.
 */
final class ConnectionsPage {

	private const PARENT = 'cartquill';
	public const SLUG    = 'cartquill-connections';
	private const MASK   = '••••••••';

	public function __construct(
		private readonly ConnectionStore $connections,
		private readonly SlackClient $client,
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

		$webhook = isset( $_POST['slack_webhook'] ) ? \sanitize_text_field( \wp_unslash( $_POST['slack_webhook'] ) ) : '';
		// Only overwrite the stored URL when a real (non-masked) value is entered.
		if ( '' !== $webhook && self::MASK !== $webhook ) {
			$existing    = $this->connections->find( SlackAction::SERVICE );
			$credentials = null !== $existing ? $existing->credentials : array();
			$credentials['webhook_url'] = \esc_url_raw( $webhook );

			$this->connections->save(
				new ConnectionRecord(
					id: $existing?->id,
					service: SlackAction::SERVICE,
					status: ConnectionRecord::STATUS_CONNECTED,
					credentials: $credentials,
				)
			);
		}

		$this->redirect_back( 'saved' );
	}

	public function handle_test(): void {
		$this->authorize( 'cartquill_test_connection' );

		$connection = $this->connections->find( SlackAction::SERVICE );
		$webhook    = null !== $connection ? (string) $connection->credential( 'webhook_url', '' ) : '';
		if ( '' === $webhook ) {
			$this->redirect_back( 'test_error' );
		}

		$result = $this->client->post( $webhook, '', \__( 'CartQuill test message — your Slack connection is working.', 'cartquill' ) );

		$this->connections->save(
			$connection->with_status( $result->ok ? ConnectionRecord::STATUS_CONNECTED : ConnectionRecord::STATUS_ERROR )
		);

		$this->redirect_back( $result->ok ? 'test_ok' : 'test_error' );
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice     = isset( $_GET['cartquill_notice'] ) ? \sanitize_text_field( \wp_unslash( $_GET['cartquill_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$connection = $this->connections->find( SlackAction::SERVICE );
		$configured = null !== $connection && $connection->is_configured();
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'Connections', 'cartquill' ); ?></h1>
			<?php $this->render_notice( $notice ); ?>

			<p><?php echo \esc_html__( 'Connect your own external services. Credentials are stored encrypted and used only from your site.', 'cartquill' ); ?></p>

			<h2><?php echo \esc_html__( 'Slack', 'cartquill' ); ?>
				<?php if ( $configured ) : ?>
					<span class="description"><?php echo \esc_html( $this->status_label( (string) $connection->status ) ); ?></span>
				<?php endif; ?>
			</h2>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<?php \wp_nonce_field( 'cartquill_save_connection' ); ?>
				<input type="hidden" name="action" value="cartquill_save_connection" />
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
				<?php \submit_button( \__( 'Save connection', 'cartquill' ) ); ?>
			</form>

			<?php if ( $configured ) : ?>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
					<?php \wp_nonce_field( 'cartquill_test_connection' ); ?>
					<input type="hidden" name="action" value="cartquill_test_connection" />
					<button type="submit" class="button"><?php echo \esc_html__( 'Test connection', 'cartquill' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function status_label( string $status ): string {
		return match ( $status ) {
			ConnectionRecord::STATUS_CONNECTED => \__( '✓ Connected', 'cartquill' ),
			ConnectionRecord::STATUS_ERROR     => \__( '✗ Connection error', 'cartquill' ),
			default                            => \__( 'Not configured', 'cartquill' ),
		};
	}

	private function render_notice( string $notice ): void {
		$map = array(
			'saved'      => array( 'success', \__( 'Connection saved.', 'cartquill' ) ),
			'test_ok'    => array( 'success', \__( 'Test message sent — check your Slack channel.', 'cartquill' ) ),
			'test_error' => array( 'error', \__( 'Could not reach Slack. Check the webhook URL and try again.', 'cartquill' ) ),
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
