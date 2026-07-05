<?php
/**
 * Per-step "rewrite with AI" controls in the flow editor.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Admin\FlowEditorPage;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\FlowRepository;

/**
 * Adds a "rewrite this step" form to each step in the editor and handles the
 * submission by varying that step's body via the proxy and saving it back as a
 * draft. Varied copy lands in the editor for review — it is never auto-sent.
 * Registered by the AI add-on only when the AI plan is active.
 */
final class AiRewriteController {

	public function __construct(
		private readonly AiFlowGenerator $generator,
		private readonly FlowRepository $flows,
		private readonly AiDisclosure $disclosure,
	) {}

	public function register(): void {
		\add_action( 'cartquill_flow_editor_after_form', array( $this, 'render_controls' ) );
		\add_action( 'admin_post_cartquill_ai_rewrite', array( $this, 'handle_rewrite' ) );
	}

	public function render_controls( FlowRecord $flow ): void {
		if ( null === $flow->id || array() === $flow->steps ) {
			return;
		}
		?>
		<h2><?php echo \esc_html__( 'Rewrite with AI', 'cartquill' ); ?></h2>
		<p class="description"><?php echo \esc_html__( 'Vary a step\'s copy. The draft opens back here for review — nothing is sent.', 'cartquill' ); ?></p>
		<?php $acknowledged = $this->disclosure->is_acknowledged(); ?>
		<div class="notice notice-info inline" style="padding:8px 12px">
			<p><?php echo \esc_html( $this->disclosure->summary() ); ?></p>
			<p>
				<a href="<?php echo \esc_url( AiDisclosure::TERMS_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo \esc_html__( 'Terms of Service', 'cartquill' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo \esc_url( AiDisclosure::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo \esc_html__( 'Privacy Policy', 'cartquill' ); ?></a>
			</p>
		</div>
		<?php foreach ( $flow->steps as $i => $step ) : ?>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px">
				<?php \wp_nonce_field( 'cartquill_ai_rewrite' ); ?>
				<input type="hidden" name="action" value="cartquill_ai_rewrite" />
				<input type="hidden" name="flow" value="<?php echo (int) $flow->id; ?>" />
				<input type="hidden" name="step" value="<?php echo (int) $i; ?>" />
				<label>
					<strong><?php printf( \esc_html__( 'Step %d', 'cartquill' ), (int) $i + 1 ); ?>:</strong>
					<input type="text" name="instruction" class="regular-text" placeholder="<?php echo \esc_attr__( 'e.g. make it shorter and warmer', 'cartquill' ); ?>" />
				</label>
				<?php if ( ! $acknowledged ) : ?>
					<label style="display:block;margin-top:4px">
						<input type="checkbox" name="ai_ack" value="1" required />
						<?php echo \esc_html__( 'Send this step to the CartQuill AI service (see disclosure above).', 'cartquill' ); ?>
					</label>
				<?php endif; ?>
				<button type="submit" class="button"><?php echo \esc_html__( 'Rewrite', 'cartquill' ); ?></button>
			</form>
		<?php endforeach; ?>
		<?php
	}

	public function handle_rewrite(): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( 'cartquill_ai_rewrite' ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'cartquill' ) );
		}

		// No proxy request until the external-service disclosure is acknowledged.
		if ( ! $this->disclosure->is_acknowledged() ) {
			if ( empty( $_POST['ai_ack'] ) ) {
				$flow_id = isset( $_POST['flow'] ) ? (int) $_POST['flow'] : 0;
				\wp_safe_redirect(
					\add_query_arg(
						array( 'page' => FlowEditorPage::SLUG, 'flow' => $flow_id, 'cartquill_ai_error' => 1 ),
						\admin_url( 'admin.php' )
					)
				);
				exit;
			}
			$this->disclosure->acknowledge();
		}

		$id          = isset( $_POST['flow'] ) ? (int) $_POST['flow'] : 0;
		$index       = isset( $_POST['step'] ) ? (int) $_POST['step'] : -1;
		$instruction = isset( $_POST['instruction'] ) ? \sanitize_text_field( \wp_unslash( $_POST['instruction'] ) ) : '';

		$flow    = $this->flows->find( $id );
		$updated = null !== $flow ? $this->generator->rewrite_step( $flow, $index, $instruction ) : null;

		$args = array( 'page' => FlowEditorPage::SLUG, 'flow' => $id );
		if ( null !== $updated ) {
			$this->flows->save( $updated );
			$args['cartquill_ai_rewritten'] = 1;
		} else {
			$args['cartquill_ai_error'] = 1;
		}

		\wp_safe_redirect( \add_query_arg( $args, \admin_url( 'admin.php' ) ) );
		exit;
	}
}
