<?php
/**
 * "Generate with AI" admin screen.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Ai\AiDisclosure;
use CartQuill\Ai\AiFlowGenerator;
use CartQuill\Ai\GenerationResult;
use CartQuill\Ai\RateLimiter;
use CartQuill\Ai\StoreContext;
use CartQuill\Flow\FlowLibrary;

/**
 * A store owner picks a flow type, gives a little store context, and the AI
 * add-on drafts a flow. On success it redirects into the builder so the copy
 * is reviewed before activation — generated flows are never auto-sent. Failure
 * modes (not licensed / rate-limited / proxy error) surface as an admin notice
 * rather than blocking the store.
 */
final class AiGeneratePage {

	private const PARENT = 'cartquill';
	public const SLUG    = 'cartquill-ai-generate';

	public function __construct(
		private readonly AiFlowGenerator $generator,
		private readonly FlowLibrary $library,
		private readonly AiDisclosure $disclosure,
		private readonly RateLimiter $limiter,
	) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_post_cartquill_ai_generate', array( $this, 'handle_generate' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			self::PARENT,
			\__( 'Generate with AI', 'cartquill' ),
			\__( 'Generate with AI', 'cartquill' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_generate(): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( 'cartquill_ai_generate' ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'cartquill' ) );
		}

		// No proxy request is made until the external-service disclosure is
		// explicitly acknowledged (WP.org guideline 8).
		if ( ! $this->disclosure->is_acknowledged() ) {
			if ( empty( $_POST['ai_ack'] ) ) {
				\wp_safe_redirect(
					\add_query_arg( 'cartquill_ai_error', 'disclosure', \admin_url( 'admin.php?page=' . self::SLUG ) )
				);
				exit;
			}
			$this->disclosure->acknowledge();
		}

		$type    = isset( $_POST['type'] ) ? \sanitize_text_field( \wp_unslash( $_POST['type'] ) ) : '';
		$tone    = isset( $_POST['tone'] ) ? \sanitize_text_field( \wp_unslash( $_POST['tone'] ) ) : '';
		$context = StoreContext::gather( $tone );

		$result = $this->generator->generate( $type, $context );

		if ( $result->is_ok() && null !== $result->flow ) {
			// The generated draft opens in the builder for review — never auto-sent.
			\wp_safe_redirect(
				\admin_url( 'admin.php?page=' . FlowBuilderPage::SLUG . '&flow=' . (int) $result->flow->id )
			);
			exit;
		}

		\wp_safe_redirect(
			\add_query_arg(
				'cartquill_ai_error',
				$result->status,
				\admin_url( 'admin.php?page=' . self::SLUG )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$error = isset( $_GET['cartquill_ai_error'] ) ? \sanitize_text_field( \wp_unslash( $_GET['cartquill_ai_error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'Generate a flow with AI', 'cartquill' ); ?></h1>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo \esc_html( $this->error_message( $error ) ); ?></p></div>
			<?php endif; ?>
			<p><?php echo \esc_html__( 'Pick a flow type and we\'ll draft the emails for you. Nothing is sent — the draft opens in the builder for you to review and activate.', 'cartquill' ); ?></p>
			<p class="description">
				<?php
				/* translators: %d: number of AI generations remaining. */
				printf( \esc_html__( '%d AI generations remaining this period.', 'cartquill' ), (int) $this->limiter->remaining() );
				$ff_reset = $this->limiter->reset_at();
				if ( $ff_reset > time() ) {
					echo ' ';
					/* translators: %s: human-readable time until the allowance resets. */
					printf( \esc_html__( 'Resets in %s.', 'cartquill' ), \esc_html( \human_time_diff( time(), $ff_reset ) ) );
				}
				?>
			</p>
			<div class="notice notice-info inline" style="padding:8px 12px">
				<p><strong><?php echo \esc_html__( 'Uses an external AI service', 'cartquill' ); ?></strong></p>
				<p><?php echo \esc_html( $this->disclosure->summary() ); ?></p>
				<p>
					<a href="<?php echo \esc_url( AiDisclosure::TERMS_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo \esc_html__( 'Terms of Service', 'cartquill' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo \esc_url( AiDisclosure::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo \esc_html__( 'Privacy Policy', 'cartquill' ); ?></a>
				</p>
			</div>
			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<?php \wp_nonce_field( 'cartquill_ai_generate' ); ?>
				<input type="hidden" name="action" value="cartquill_ai_generate" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="cartquill-ai-type"><?php echo \esc_html__( 'Flow type', 'cartquill' ); ?></label></th>
						<td>
							<select name="type" id="cartquill-ai-type">
								<?php foreach ( $this->library->templates() as $template ) : ?>
									<option value="<?php echo \esc_attr( $template->type ); ?>"><?php echo \esc_html( $template->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cartquill-ai-tone"><?php echo \esc_html__( 'Tone', 'cartquill' ); ?></label></th>
						<td><input type="text" name="tone" id="cartquill-ai-tone" class="regular-text" placeholder="<?php echo \esc_attr__( 'e.g. warm and playful', 'cartquill' ); ?>" /></td>
					</tr>
				</table>
				<?php if ( ! $this->disclosure->is_acknowledged() ) : ?>
					<p>
						<label>
							<input type="checkbox" name="ai_ack" value="1" required />
							<?php echo \esc_html__( 'I understand my store context and license key will be sent to the CartQuill AI service.', 'cartquill' ); ?>
						</label>
					</p>
				<?php endif; ?>
				<p class="submit"><button type="submit" class="button button-primary"><?php echo \esc_html__( 'Generate draft', 'cartquill' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private function error_message( string $status ): string {
		return match ( $status ) {
			'disclosure'                   => \__( 'Please acknowledge the AI service disclosure before generating.', 'cartquill' ),
			GenerationResult::NOT_LICENSED => \__( 'The AI Flow Generation add-on is not active. Add your license key to enable it.', 'cartquill' ),
			GenerationResult::RATE_LIMITED => \__( 'You\'ve reached the AI usage limit for now. Please try again later.', 'cartquill' ),
			default                        => \__( 'The AI service is temporarily unavailable. Please try again.', 'cartquill' ),
		};
	}
}
