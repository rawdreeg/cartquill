<?php
/**
 * Flow library + installed-flows admin screen.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Flow\FlowInstaller;
use CartQuill\Flow\FlowLibrary;
use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\FlowRepository;

/**
 * Lists installable templates (with a step/timing preview) and the store's
 * installed flows (with activate/deactivate + edit). Install/activate/deactivate
 * post to admin-post handlers so each is a nonce-guarded, redirecting action.
 */
final class FlowLibraryPage {

	private const PARENT = 'cartquill';
	public const SLUG    = 'cartquill-flows';

	public function __construct(
		private readonly FlowLibrary $library,
		private readonly FlowInstaller $installer,
		private readonly FlowRepository $flows,
	) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_post_cartquill_install_flow', array( $this, 'handle_install' ) );
		\add_action( 'admin_post_cartquill_set_status', array( $this, 'handle_set_status' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			self::PARENT,
			\__( 'Flows', 'cartquill' ),
			\__( 'Flows', 'cartquill' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_install(): void {
		$this->authorize( 'cartquill_install_flow' );
		// authorize() wp_die()s unless check_admin_referer() passes, so the nonce
		// is verified before any read below; PHPCS cannot follow it through the
		// helper and reports the check as missing.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type = isset( $_POST['type'] ) ? \sanitize_text_field( \wp_unslash( $_POST['type'] ) ) : '';
		$this->installer->install( $type );
		$this->redirect_back();
	}

	public function handle_set_status(): void {
		$this->authorize( 'cartquill_set_status' );
		// Nonce verified in authorize() above, as in handle_install().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id     = isset( $_POST['flow'] ) ? (int) $_POST['flow'] : 0;
		$status = isset( $_POST['status'] ) ? \sanitize_text_field( \wp_unslash( $_POST['status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$flow = $this->flows->find( $id );
		if ( null !== $flow && in_array( $status, array( FlowRecord::STATUS_ACTIVE, FlowRecord::STATUS_DRAFT, FlowRecord::STATUS_PAUSED ), true ) ) {
			$this->flows->save(
				new FlowRecord( $flow->id, $flow->name, $flow->type, $status, $flow->source, $flow->steps, $flow->created_at )
			);
		}
		$this->redirect_back();
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$installed = $this->flows->all();
		$by_type   = $this->latest_by_type( $installed );
		?>
		<div class="wrap cartquill-admin">
			<h1><?php echo \esc_html__( 'CartQuill Flows', 'cartquill' ); ?></h1>

			<h2><?php echo \esc_html__( 'Your flows', 'cartquill' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php echo \esc_html__( 'Name', 'cartquill' ); ?></th>
					<th><?php echo \esc_html__( 'Status', 'cartquill' ); ?></th>
					<th><?php echo \esc_html__( 'Steps', 'cartquill' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( array() === $installed ) : ?>
					<tr><td colspan="4"><?php echo \esc_html__( 'No flows installed yet — install one from the library below.', 'cartquill' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $installed as $flow ) : ?>
					<tr>
						<td><?php echo \esc_html( $flow->name ); ?></td>
						<td><?php $this->status_badge( $flow->status ); ?></td>
						<td><?php echo \esc_html( (string) count( $flow->steps ) ); ?></td>
						<td>
							<?php $this->status_button( $flow ); ?>
							<a class="button" href="<?php echo \esc_url( \admin_url( 'admin.php?page=' . FlowBuilderPage::SLUG . '&flow=' . (int) $flow->id ) ); ?>">
								<?php echo \esc_html__( 'Edit', 'cartquill' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo \esc_html__( 'Flow library', 'cartquill' ); ?></h2>
			<div class="cartquill-cards">
				<?php foreach ( $this->library->templates() as $template ) : ?>
					<div class="cartquill-card">
						<h3><?php echo \esc_html( $template->name ); ?></h3>
						<ol>
							<?php foreach ( $template->steps as $step ) : ?>
								<li>
									<strong><?php echo \esc_html( $this->humanize_delay( $step ) ); ?>:</strong>
									<?php echo \esc_html( $step->subject ); ?>
								</li>
							<?php endforeach; ?>
						</ol>
						<?php $existing = $by_type[ $template->type ] ?? null; ?>
						<?php if ( null !== $existing ) : ?>
							<p>
								<span class="cartquill-badge cartquill-badge--active">&#10003; <?php echo \esc_html__( 'Installed', 'cartquill' ); ?></span>
								<?php $this->status_badge( $existing->status ); ?>
							</p>
							<?php $this->status_button( $existing ); ?>
							<a class="button" href="<?php echo \esc_url( \admin_url( 'admin.php?page=' . FlowBuilderPage::SLUG . '&flow=' . (int) $existing->id ) ); ?>">
								<?php echo \esc_html__( 'Edit flow', 'cartquill' ); ?>
							</a>
						<?php else : ?>
							<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
								<?php \wp_nonce_field( 'cartquill_install_flow' ); ?>
								<input type="hidden" name="action" value="cartquill_install_flow" />
								<input type="hidden" name="type" value="<?php echo \esc_attr( $template->type ); ?>" />
								<button type="submit" class="button button-primary"><?php echo \esc_html__( 'Install', 'cartquill' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The most recently installed flow per template type, so each library card
	 * can offer manage actions instead of a duplicate Install.
	 *
	 * @param list<FlowRecord> $installed
	 * @return array<string, FlowRecord>
	 */
	private function latest_by_type( array $installed ): array {
		$by_type = array();
		foreach ( $installed as $flow ) {
			$current = $by_type[ $flow->type ] ?? null;
			if ( null === $current || (int) $flow->id >= (int) $current->id ) {
				$by_type[ $flow->type ] = $flow;
			}
		}
		return $by_type;
	}

	/** A colored pill for a flow status (active green, paused amber, draft gray). */
	private function status_badge( string $status ): void {
		$variant = match ( $status ) {
			FlowRecord::STATUS_ACTIVE => ' cartquill-badge--active',
			FlowRecord::STATUS_PAUSED => ' cartquill-badge--paused',
			default                   => '',
		};
		?>
		<span class="cartquill-badge<?php echo \esc_attr( $variant ); ?>"><?php echo \esc_html( $status ); ?></span>
		<?php
	}

	private function status_button( FlowRecord $flow ): void {
		$next  = $flow->is_active() ? FlowRecord::STATUS_DRAFT : FlowRecord::STATUS_ACTIVE;
		$label = $flow->is_active() ? \__( 'Deactivate', 'cartquill' ) : \__( 'Activate', 'cartquill' );
		?>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php \wp_nonce_field( 'cartquill_set_status' ); ?>
			<input type="hidden" name="action" value="cartquill_set_status" />
			<input type="hidden" name="flow" value="<?php echo (int) $flow->id; ?>" />
			<input type="hidden" name="status" value="<?php echo \esc_attr( $next ); ?>" />
			<button type="submit" class="button"><?php echo \esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function humanize_delay( FlowStep $step ): string {
		$seconds = $step->delay;
		if ( 0 === $seconds ) {
			return \__( 'Immediately', 'cartquill' );
		}
		if ( $seconds % DAY_IN_SECONDS === 0 ) {
			/* translators: %d: number of days. */
			return sprintf( \_n( '%d day', '%d days', $seconds / DAY_IN_SECONDS, 'cartquill' ), $seconds / DAY_IN_SECONDS );
		}
		if ( $seconds % HOUR_IN_SECONDS === 0 ) {
			/* translators: %d: number of hours. */
			return sprintf( \_n( '%d hour', '%d hours', $seconds / HOUR_IN_SECONDS, 'cartquill' ), $seconds / HOUR_IN_SECONDS );
		}
		/* translators: %d: number of minutes. */
		return sprintf( \_n( '%d minute', '%d minutes', max( 1, (int) round( $seconds / 60 ) ), 'cartquill' ), max( 1, (int) round( $seconds / 60 ) ) );
	}

	private function authorize( string $nonce_action ): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( $nonce_action ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'cartquill' ) );
		}
	}

	private function redirect_back(): void {
		\wp_safe_redirect( \admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
