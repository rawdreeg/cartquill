<?php
/**
 * Deliverability admin screen: Resend key + domain-auth wizard.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Deliverability\DomainStatus;
use FlowForge\Deliverability\EspSettings;
use FlowForge\Deliverability\HttpResendClient;
use FlowForge\Deliverability\ResendException;

/**
 * Lets the store connect its own Resend account (API key stored encrypted) and
 * walks it through authenticating a sending domain: enter the domain, add the
 * SPF/DKIM/DMARC records shown, then check verification status live against
 * Resend. The key input is write-only — an existing key is shown masked and only
 * overwritten when a new value is submitted.
 */
final class DeliverabilityPage {

	private const PARENT = 'flowforge';
	public const SLUG    = 'flowforge-deliverability';
	private const STATUS_TRANSIENT = 'flowforge_esp_domain_status';
	private const MASK   = '••••••••';

	public function __construct( private readonly EspSettings $esp ) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_post_flowforge_save_esp', array( $this, 'handle_save' ) );
		\add_action( 'admin_post_flowforge_provision_domain', array( $this, 'handle_provision' ) );
		\add_action( 'admin_post_flowforge_verify_domain', array( $this, 'handle_verify' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			self::PARENT,
			\__( 'Deliverability', 'flowforge' ),
			\__( 'Deliverability', 'flowforge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_save(): void {
		$this->authorize( 'flowforge_save_esp' );

		$key = isset( $_POST['api_key'] ) ? \sanitize_text_field( \wp_unslash( $_POST['api_key'] ) ) : '';
		// Only overwrite the stored key when a real (non-masked) value is entered.
		if ( '' !== $key && self::MASK !== $key ) {
			$this->esp->set_api_key( $key );
		}

		if ( isset( $_POST['domain'] ) ) {
			$this->esp->set_domain( \sanitize_text_field( \wp_unslash( $_POST['domain'] ) ) );
		}

		$secret = isset( $_POST['webhook_secret'] ) ? \sanitize_text_field( \wp_unslash( $_POST['webhook_secret'] ) ) : '';
		if ( '' !== $secret && self::MASK !== $secret ) {
			$this->esp->set_webhook_secret( $secret );
		}

		\delete_transient( self::STATUS_TRANSIENT );
		$this->redirect_back( 'saved' );
	}

	public function handle_provision(): void {
		$this->authorize( 'flowforge_provision_domain' );

		$domain = $this->esp->domain();
		if ( '' === $domain || ! $this->esp->has_key() ) {
			$this->redirect_back( 'verify_error' );
		}

		try {
			$status = ( new HttpResendClient( $this->esp->api_key() ) )->create_domain( $domain );
			\set_transient( self::STATUS_TRANSIENT, $this->snapshot( $status ), MINUTE_IN_SECONDS );
			$this->redirect_back( 'provisioned' );
		} catch ( ResendException $e ) {
			$this->redirect_back( 'verify_error' );
		}
	}

	public function handle_verify(): void {
		$this->authorize( 'flowforge_verify_domain' );

		$domain = $this->esp->domain();
		if ( '' === $domain || ! $this->esp->has_key() ) {
			$this->redirect_back( 'verify_error' );
		}

		try {
			$status = ( new HttpResendClient( $this->esp->api_key() ) )->domain_status( $domain );
			\set_transient( self::STATUS_TRANSIENT, $this->snapshot( $status ), MINUTE_IN_SECONDS );
			$this->redirect_back( 'verified' );
		} catch ( ResendException $e ) {
			$this->redirect_back( 'verify_error' );
		}
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = isset( $_GET['flowforge_notice'] ) ? \sanitize_text_field( \wp_unslash( $_GET['flowforge_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'Deliverability', 'flowforge' ); ?></h1>
			<?php $this->render_notice( $notice ); ?>

			<p><?php echo \esc_html__( 'Send through your own Resend account. Your API key is stored encrypted and used only from your site — nothing is sent on your behalf.', 'flowforge' ); ?></p>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<?php \wp_nonce_field( 'flowforge_save_esp' ); ?>
				<input type="hidden" name="action" value="flowforge_save_esp" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ff-esp-key"><?php echo \esc_html__( 'Resend API key', 'flowforge' ); ?></label></th>
						<td><input type="text" id="ff-esp-key" name="api_key" class="regular-text" autocomplete="off"
							value="<?php echo $this->esp->has_key() ? \esc_attr( self::MASK ) : ''; ?>"
							placeholder="re_..." /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ff-esp-domain"><?php echo \esc_html__( 'Sending domain', 'flowforge' ); ?></label></th>
						<td><input type="text" id="ff-esp-domain" name="domain" class="regular-text"
							value="<?php echo \esc_attr( $this->esp->domain() ); ?>" placeholder="mail.example.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ff-esp-secret"><?php echo \esc_html__( 'Webhook signing secret', 'flowforge' ); ?></label></th>
						<td>
							<input type="text" id="ff-esp-secret" name="webhook_secret" class="regular-text" autocomplete="off"
								value="<?php echo $this->esp->has_webhook_secret() ? \esc_attr( self::MASK ) : ''; ?>"
								placeholder="whsec_..." />
							<p class="description">
								<?php echo \esc_html__( 'Point a Resend webhook at the URL below and paste its signing secret here to receive delivery, bounce and complaint events:', 'flowforge' ); ?>
								<br /><code><?php echo \esc_html( \add_query_arg( \FlowForge\Deliverability\WebhookEndpoint::PARAM, 'resend', \home_url( '/' ) ) ); ?></code>
							</p>
						</td>
					</tr>
				</table>
				<?php \submit_button( \__( 'Save connection', 'flowforge' ) ); ?>
			</form>

			<?php if ( $this->esp->has_key() && '' !== $this->esp->domain() ) : ?>
				<h2><?php echo \esc_html__( 'Authenticate your domain', 'flowforge' ); ?></h2>
				<p><?php echo \esc_html__( 'Step 1: register the domain in Resend to get your DNS records. Step 2: add the SPF, DKIM and DMARC records at your DNS provider. Step 3: check verification. Authenticated domains land in the inbox instead of spam.', 'flowforge' ); ?></p>
				<p>
					<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<?php \wp_nonce_field( 'flowforge_provision_domain' ); ?>
						<input type="hidden" name="action" value="flowforge_provision_domain" />
						<button type="submit" class="button"><?php echo \esc_html__( 'Add domain to Resend', 'flowforge' ); ?></button>
					</form>
					<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<?php \wp_nonce_field( 'flowforge_verify_domain' ); ?>
						<input type="hidden" name="action" value="flowforge_verify_domain" />
						<button type="submit" class="button button-primary"><?php echo \esc_html__( 'Check verification status', 'flowforge' ); ?></button>
					</form>
				</p>
				<?php $this->render_status(); ?>
				<?php $this->render_dmarc_guidance(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_status(): void {
		$snapshot = \get_transient( self::STATUS_TRANSIENT );
		if ( ! is_array( $snapshot ) ) {
			return;
		}
		$verified = ! empty( $snapshot['verified'] );
		?>
		<p>
			<strong><?php echo \esc_html__( 'Status:', 'flowforge' ); ?></strong>
			<?php
			$state = (string) ( $snapshot['state'] ?? 'pending' );
			if ( $verified ) : ?>
				<span style="color:#008a20">&#10003; <?php echo \esc_html__( 'Verified', 'flowforge' ); ?></span>
			<?php elseif ( 'failed' === $state ) : ?>
				<span style="color:#b32d2e"><?php echo \esc_html__( 'Failed — check your DNS records', 'flowforge' ); ?></span>
			<?php else : ?>
				<span style="color:#996800"><?php echo \esc_html( sprintf( \__( 'Pending (%s) — add the records below, DNS can take a while to propagate', 'flowforge' ), $state ) ); ?></span>
			<?php endif; ?>
		</p>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr>
				<th><?php echo \esc_html__( 'Record', 'flowforge' ); ?></th>
				<th><?php echo \esc_html__( 'Type', 'flowforge' ); ?></th>
				<th><?php echo \esc_html__( 'Name', 'flowforge' ); ?></th>
				<th><?php echo \esc_html__( 'Value', 'flowforge' ); ?></th>
				<th><?php echo \esc_html__( 'Status', 'flowforge' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( (array) ( $snapshot['records'] ?? array() ) as $record ) : ?>
				<tr>
					<td><?php echo \esc_html( (string) ( $record['purpose'] ?? '' ) ); ?></td>
					<td><?php echo \esc_html( (string) ( $record['type'] ?? '' ) ); ?></td>
					<td><code><?php echo \esc_html( (string) ( $record['name'] ?? '' ) ); ?></code></td>
					<td><code><?php echo \esc_html( (string) ( $record['value'] ?? '' ) ); ?></code></td>
					<td><?php echo \esc_html( (string) ( $record['status'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Resend authenticates SPF + DKIM but does not return a DMARC record, so the
	 * wizard always shows a recommended DMARC policy to complete SPF/DKIM/DMARC
	 * guidance. It is advisory — Resend can't verify it — so it is presented
	 * separately from the verifiable records.
	 */
	private function render_dmarc_guidance(): void {
		$domain = $this->esp->domain();
		if ( '' === $domain ) {
			return;
		}
		?>
		<h3><?php echo \esc_html__( 'Recommended: DMARC', 'flowforge' ); ?></h3>
		<p class="description"><?php echo \esc_html__( 'DMARC is not managed by Resend, but adding a policy record improves deliverability. Start with a monitoring policy and tighten it over time.', 'flowforge' ); ?></p>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr>
				<th><?php echo \esc_html__( 'Type', 'flowforge' ); ?></th>
				<th><?php echo \esc_html__( 'Name', 'flowforge' ); ?></th>
				<th><?php echo \esc_html__( 'Value', 'flowforge' ); ?></th>
			</tr></thead>
			<tbody><tr>
				<td>TXT</td>
				<td><code><?php echo \esc_html( '_dmarc.' . $domain ); ?></code></td>
				<td><code>v=DMARC1; p=none; rua=mailto:dmarc@<?php echo \esc_html( $domain ); ?></code></td>
			</tr></tbody>
		</table>
		<?php
	}

	/**
	 * @return array<string, mixed>
	 */
	private function snapshot( DomainStatus $status ): array {
		$records = array();
		foreach ( $status->records as $record ) {
			$records[] = array(
				'purpose' => $record->purpose,
				'type'    => $record->type,
				'name'    => $record->name,
				'value'   => $record->value,
				'status'  => $record->status,
			);
		}
		return array(
			'verified' => $status->verified,
			'state'    => $status->state,
			'records'  => $records,
		);
	}

	private function render_notice( string $notice ): void {
		$map = array(
			'saved'        => array( 'success', \__( 'Connection saved.', 'flowforge' ) ),
			'provisioned'  => array( 'success', \__( 'Domain registered with Resend. Add the DNS records below, then check verification.', 'flowforge' ) ),
			'verified'     => array( 'success', \__( 'Verification status refreshed.', 'flowforge' ) ),
			'verify_error' => array( 'error', \__( 'Could not reach Resend. Check your API key and domain, then try again.', 'flowforge' ) ),
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
			\wp_die( \esc_html__( 'Not allowed.', 'flowforge' ) );
		}
	}

	private function redirect_back( string $notice ): void {
		\wp_safe_redirect( \add_query_arg(
			array( 'page' => self::SLUG, 'flowforge_notice' => $notice ),
			\admin_url( 'admin.php' )
		) );
		exit;
	}
}
