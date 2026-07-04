<?php
/**
 * Flow library + installed-flows admin screen.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Admin;

use FlowForge\Flow\FlowInstaller;
use FlowForge\Flow\FlowLibrary;
use FlowForge\Flow\FlowStep;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\FlowRepository;

/**
 * Lists installable templates (with a step/timing preview) and the store's
 * installed flows (with activate/deactivate + edit). Install/activate/deactivate
 * post to admin-post handlers so each is a nonce-guarded, redirecting action.
 */
final class FlowLibraryPage {

	private const PARENT = 'flowforge';
	public const SLUG    = 'flowforge-flows';

	public function __construct(
		private readonly FlowLibrary $library,
		private readonly FlowInstaller $installer,
		private readonly FlowRepository $flows,
	) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_post_flowforge_install_flow', array( $this, 'handle_install' ) );
		\add_action( 'admin_post_flowforge_set_status', array( $this, 'handle_set_status' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			self::PARENT,
			\__( 'Flows', 'flowforge' ),
			\__( 'Flows', 'flowforge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	// --- Actions ----------------------------------------------------------

	public function handle_install(): void {
		$this->authorize( 'flowforge_install_flow' );
		$type = isset( $_POST['type'] ) ? \sanitize_text_field( \wp_unslash( $_POST['type'] ) ) : '';
		$this->installer->install( $type );
		$this->redirect_back();
	}

	public function handle_set_status(): void {
		$this->authorize( 'flowforge_set_status' );
		$id     = isset( $_POST['flow'] ) ? (int) $_POST['flow'] : 0;
		$status = isset( $_POST['status'] ) ? \sanitize_text_field( \wp_unslash( $_POST['status'] ) ) : '';

		$flow = $this->flows->find( $id );
		if ( null !== $flow && in_array( $status, array( FlowRecord::STATUS_ACTIVE, FlowRecord::STATUS_DRAFT, FlowRecord::STATUS_PAUSED ), true ) ) {
			$this->flows->save(
				new FlowRecord( $flow->id, $flow->name, $flow->type, $status, $flow->source, $flow->steps, $flow->created_at )
			);
		}
		$this->redirect_back();
	}

	// --- Rendering --------------------------------------------------------

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'FlowForge Flows', 'flowforge' ); ?></h1>

			<h2><?php echo \esc_html__( 'Your flows', 'flowforge' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php echo \esc_html__( 'Name', 'flowforge' ); ?></th>
					<th><?php echo \esc_html__( 'Status', 'flowforge' ); ?></th>
					<th><?php echo \esc_html__( 'Steps', 'flowforge' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php $installed = $this->flows->all(); ?>
				<?php if ( array() === $installed ) : ?>
					<tr><td colspan="4"><?php echo \esc_html__( 'No flows installed yet — install one from the library below.', 'flowforge' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $installed as $flow ) : ?>
					<tr>
						<td><?php echo \esc_html( $flow->name ); ?></td>
						<td><?php echo \esc_html( $flow->status ); ?></td>
						<td><?php echo \esc_html( (string) count( $flow->steps ) ); ?></td>
						<td>
							<?php $this->status_button( $flow ); ?>
							<a class="button" href="<?php echo \esc_url( \admin_url( 'admin.php?page=' . FlowEditorPage::SLUG . '&flow=' . (int) $flow->id ) ); ?>">
								<?php echo \esc_html__( 'Edit', 'flowforge' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em"><?php echo \esc_html__( 'Flow library', 'flowforge' ); ?></h2>
			<div style="display:flex;flex-wrap:wrap;gap:16px">
				<?php foreach ( $this->library->templates() as $template ) : ?>
					<div class="card" style="padding:16px;max-width:320px">
						<h3><?php echo \esc_html( $template->name ); ?></h3>
						<ol>
							<?php foreach ( $template->steps as $step ) : ?>
								<li>
									<strong><?php echo \esc_html( $this->humanize_delay( $step ) ); ?>:</strong>
									<?php echo \esc_html( $step->subject ); ?>
								</li>
							<?php endforeach; ?>
						</ol>
						<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
							<?php \wp_nonce_field( 'flowforge_install_flow' ); ?>
							<input type="hidden" name="action" value="flowforge_install_flow" />
							<input type="hidden" name="type" value="<?php echo \esc_attr( $template->type ); ?>" />
							<button type="submit" class="button button-primary"><?php echo \esc_html__( 'Install', 'flowforge' ); ?></button>
						</form>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function status_button( FlowRecord $flow ): void {
		$next  = $flow->is_active() ? FlowRecord::STATUS_DRAFT : FlowRecord::STATUS_ACTIVE;
		$label = $flow->is_active() ? \__( 'Deactivate', 'flowforge' ) : \__( 'Activate', 'flowforge' );
		?>
		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php \wp_nonce_field( 'flowforge_set_status' ); ?>
			<input type="hidden" name="action" value="flowforge_set_status" />
			<input type="hidden" name="flow" value="<?php echo (int) $flow->id; ?>" />
			<input type="hidden" name="status" value="<?php echo \esc_attr( $next ); ?>" />
			<button type="submit" class="button"><?php echo \esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function humanize_delay( FlowStep $step ): string {
		$seconds = $step->delay;
		if ( 0 === $seconds ) {
			return \__( 'Immediately', 'flowforge' );
		}
		if ( $seconds % DAY_IN_SECONDS === 0 ) {
			return sprintf( \_n( '%d day', '%d days', $seconds / DAY_IN_SECONDS, 'flowforge' ), $seconds / DAY_IN_SECONDS );
		}
		if ( $seconds % HOUR_IN_SECONDS === 0 ) {
			return sprintf( \_n( '%d hour', '%d hours', $seconds / HOUR_IN_SECONDS, 'flowforge' ), $seconds / HOUR_IN_SECONDS );
		}
		return sprintf( \_n( '%d minute', '%d minutes', max( 1, (int) round( $seconds / 60 ) ), 'flowforge' ), max( 1, (int) round( $seconds / 60 ) ) );
	}

	private function authorize( string $nonce_action ): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( $nonce_action ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'flowforge' ) );
		}
	}

	private function redirect_back(): void {
		\wp_safe_redirect( \admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
