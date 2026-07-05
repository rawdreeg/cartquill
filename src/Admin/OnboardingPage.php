<?php
/**
 * First-run onboarding screen.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Settings\OptionsSettings;

/**
 * A short onboarding: confirm the from-identity and point the user at the flow
 * library. Appears once after activation (redirect), can be completed or
 * skipped, and never re-appears once done.
 */
final class OnboardingPage {

	public const SLUG = 'flowforge-onboarding';

	public function __construct(
		private readonly Onboarding $onboarding,
		private readonly OptionsSettings $settings,
	) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		\add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		\add_action( 'admin_post_flowforge_finish_onboarding', array( $this, 'handle_finish' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			'',
			\__( 'Welcome to FlowForge', 'flowforge' ),
			\__( 'Welcome', 'flowforge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Send the user to onboarding once, right after activation.
	 */
	public function maybe_redirect(): void {
		if ( \wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || \is_network_admin() ) {
			return;
		}
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->onboarding->consume_redirect() ) {
			\wp_safe_redirect( \admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}
	}

	/**
	 * Fallback discovery: if onboarding was never completed (e.g. the redirect
	 * was missed), show a dismissible notice linking to it.
	 */
	public function maybe_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) || $this->onboarding->is_complete() ) {
			return;
		}
		$url = \admin_url( 'admin.php?page=' . self::SLUG );
		echo '<div class="notice notice-info is-dismissible"><p>';
		printf(
			/* translators: %s: onboarding page URL. */
			\wp_kses_post( __( 'Finish setting up FlowForge to send your first flow. <a href="%s">Complete setup</a>.', 'flowforge' ) ),
			\esc_url( $url )
		);
		echo '</p></div>';
	}

	public function handle_finish(): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( 'flowforge_finish_onboarding' ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'flowforge' ) );
		}

		$skip = isset( $_POST['skip'] );

		if ( ! $skip && isset( $_POST['from_name'], $_POST['from_email'] ) ) {
			$this->settings->update(
				\sanitize_text_field( \wp_unslash( $_POST['from_name'] ) ),
				\sanitize_email( \wp_unslash( $_POST['from_email'] ) )
			);
		}
		$this->onboarding->mark_complete();

		$dest = isset( $_POST['go_to_library'] )
			? 'admin.php?page=' . FlowLibraryPage::SLUG
			: 'admin.php?page=flowforge';
		\wp_safe_redirect( \admin_url( $dest ) );
		exit;
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'Welcome to FlowForge', 'flowforge' ); ?></h1>
			<p><?php echo \esc_html__( 'Two quick things and you are ready to send your first flow.', 'flowforge' ); ?></p>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<?php \wp_nonce_field( 'flowforge_finish_onboarding' ); ?>
				<input type="hidden" name="action" value="flowforge_finish_onboarding" />

				<h2><?php echo \esc_html__( '1. Confirm who your emails come from', 'flowforge' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="ff-from-name"><?php echo \esc_html__( 'From name', 'flowforge' ); ?></label></th>
						<td><input id="ff-from-name" type="text" name="from_name" class="regular-text" value="<?php echo \esc_attr( $this->settings->from_name() ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ff-from-email"><?php echo \esc_html__( 'From email', 'flowforge' ); ?></label></th>
						<td><input id="ff-from-email" type="email" name="from_email" class="regular-text" value="<?php echo \esc_attr( $this->settings->from_email() ); ?>" /></td>
					</tr>
				</table>

				<h2><?php echo \esc_html__( '2. Install your first flow', 'flowforge' ); ?></h2>
				<p><?php echo \esc_html__( 'Pick a proven template from the flow library — abandoned cart, welcome, post-purchase, or win-back.', 'flowforge' ); ?></p>

				<p>
					<button type="submit" name="go_to_library" value="1" class="button button-primary"><?php echo \esc_html__( 'Finish and go to the flow library', 'flowforge' ); ?></button>
					<button type="submit" class="button"><?php echo \esc_html__( 'Finish', 'flowforge' ); ?></button>
					<button type="submit" name="skip" value="1" class="button-link" style="margin-left:8px"><?php echo \esc_html__( 'Skip for now', 'flowforge' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
