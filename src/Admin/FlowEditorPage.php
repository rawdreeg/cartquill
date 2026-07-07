<?php
/**
 * Flow editor admin screen: edit steps, delays, subjects, body, conditions.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Flow\FlowEditor;
use CartQuill\Licensing\PlanGate;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\FlowRepository;

/**
 * A hidden submenu page reached from the flow list. Renders a form for the
 * flow's name, status and per-step fields; on save it hands the posted data to
 * FlowEditor (the tested transformation) and persists. The engine reads the
 * flow fresh on each step, so edits take effect on the next send.
 *
 * Activation is gated by the held plan: the {@see PlanGate} may deny setting a
 * flow active (over the workflow cap, or using conditional logic the tier does
 * not include). A denied activation keeps the other edits but leaves the flow in
 * its prior status.
 */
final class FlowEditorPage {

	private const PARENT = 'cartquill';
	public const SLUG    = 'cartquill-flow-edit';

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly FlowEditor $editor,
		private readonly PlanGate $plan_gate,
	) {}

	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu' ) );
		\add_action( 'admin_post_cartquill_save_flow', array( $this, 'handle_save' ) );
	}

	public function add_menu(): void {
		\add_submenu_page(
			'',
			\__( 'Edit flow', 'cartquill' ),
			\__( 'Edit flow', 'cartquill' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_save(): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( 'cartquill_save_flow' ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'cartquill' ) );
		}

		$id   = isset( $_POST['flow'] ) ? (int) $_POST['flow'] : 0;
		$flow = $this->flows->find( $id );
		if ( null !== $flow ) {
			$steps = $this->posted_steps();

			if ( isset( $_POST['add_step'] ) ) {
				$steps[] = array( 'delay' => 0, 'subject' => '', 'body' => '', 'exit_if_ordered' => false );
			}

			// wp_unslash the posted content; FlowEditor validates the shape.
			$input = array(
				'name'   => isset( $_POST['name'] ) ? \sanitize_text_field( \wp_unslash( $_POST['name'] ) ) : $flow->name,
				'status' => isset( $_POST['status'] ) ? \sanitize_text_field( \wp_unslash( $_POST['status'] ) ) : $flow->status,
				'steps'  => $steps,
			);

			$gated = $this->gate_save( $flow, $this->editor->apply( $flow, $input ) );
			$this->flows->save( $gated['record'] );

			if ( '' !== $gated['blocked'] ) {
				\wp_safe_redirect( \admin_url( 'admin.php?page=' . self::SLUG . '&flow=' . $id . '&cartquill_plan_blocked=' . rawurlencode( $gated['blocked'] ) ) );
				exit;
			}
		}

		\wp_safe_redirect( \admin_url( 'admin.php?page=' . self::SLUG . '&flow=' . $id . '&updated=1' ) );
		exit;
	}

	/**
	 * Apply the plan gate to a pending save. Returns the record to persist and the
	 * block reason ('' when the save is allowed as-is).
	 *
	 * A flow the held plan may not run active is kept out of the active status:
	 * reverted to its prior status, or paused if it was already active. This gates
	 * conditional logic on every save (not just the first activation), so a flow
	 * cannot be activated plain and then have a disallowed condition added while
	 * still running. The edits themselves are always preserved.
	 *
	 * @return array{record: FlowRecord, blocked: string}
	 */
	public function gate_save( FlowRecord $current, FlowRecord $candidate ): array {
		$blocked = $candidate->is_active() ? $this->plan_gate->activation_error( $candidate ) : '';
		if ( '' === $blocked ) {
			return array(
				'record'  => $candidate,
				'blocked' => '',
			);
		}

		$safe_status = $current->is_active() ? FlowRecord::STATUS_PAUSED : $current->status;
		return array(
			'record'  => $candidate->with_status( $safe_status ),
			'blocked' => $blocked,
		);
	}

	/**
	 * The admin message explaining why an activation was denied by the held plan.
	 */
	private function blocked_message( string $reason ): string {
		if ( PlanGate::REASON_CONDITIONAL_LOGIC === $reason ) {
			return \__( 'This flow uses conditional logic, which your plan does not include. Upgrade to activate it; your edits were saved.', 'cartquill' );
		}
		if ( PlanGate::REASON_WORKFLOW_CAP === $reason ) {
			return \__( 'You have reached your plan\'s active-workflow limit. Pause another flow or upgrade to activate this one; your edits were saved.', 'cartquill' );
		}
		return \__( 'This flow could not be activated on your current plan; your edits were saved.', 'cartquill' );
	}

	private function has_exit_condition( \CartQuill\Flow\FlowStep $step ): bool {
		foreach ( $step->conditions as $condition ) {
			if ( 'exit_if_ordered' === ( ( (array) $condition )['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function posted_steps(): array {
		$steps = array();
		$raw   = isset( $_POST['steps'] ) && is_array( $_POST['steps'] ) ? \wp_unslash( $_POST['steps'] ) : array(); // phpcs:ignore
		foreach ( $raw as $step ) {
			$step    = (array) $step;
			$steps[] = array(
				'delay'           => (int) ( $step['delay'] ?? 0 ),
				'subject'         => \sanitize_text_field( (string) ( $step['subject'] ?? '' ) ),
				'body'            => \wp_kses_post( (string) ( $step['body'] ?? '' ) ),
				'exit_if_ordered' => ! empty( $step['exit_if_ordered'] ),
				'remove'          => ! empty( $step['remove'] ),
			);
		}
		return $steps;
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$id   = isset( $_GET['flow'] ) ? (int) $_GET['flow'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flow = $this->flows->find( $id );
		if ( null === $flow ) {
			echo '<div class="wrap"><p>' . \esc_html__( 'Flow not found.', 'cartquill' ) . '</p></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'Edit flow', 'cartquill' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php echo \esc_html__( 'Flow saved.', 'cartquill' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cartquill_plan_blocked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo \esc_html( $this->blocked_message( \sanitize_key( \wp_unslash( $_GET['cartquill_plan_blocked'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cartquill_ai_rewritten'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php echo \esc_html__( 'Step rewritten. Review the draft below before activating.', 'cartquill' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cartquill_ai_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo \esc_html__( 'Could not rewrite the step (AI unavailable or usage limit reached). Your copy is unchanged.', 'cartquill' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<?php \wp_nonce_field( 'cartquill_save_flow' ); ?>
				<input type="hidden" name="action" value="cartquill_save_flow" />
				<input type="hidden" name="flow" value="<?php echo (int) $flow->id; ?>" />

				<table class="form-table">
					<tr>
						<th><label for="ff-name"><?php echo \esc_html__( 'Name', 'cartquill' ); ?></label></th>
						<td><input id="ff-name" type="text" name="name" class="regular-text" value="<?php echo \esc_attr( $flow->name ); ?>" /></td>
					</tr>
					<tr>
						<th><?php echo \esc_html__( 'Status', 'cartquill' ); ?></th>
						<td>
							<select name="status">
								<?php foreach ( array( FlowRecord::STATUS_DRAFT, FlowRecord::STATUS_ACTIVE, FlowRecord::STATUS_PAUSED ) as $status ) : ?>
									<option value="<?php echo \esc_attr( $status ); ?>" <?php \selected( $flow->status, $status ); ?>><?php echo \esc_html( $status ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php echo \esc_html__( 'Steps', 'cartquill' ); ?></h2>
				<?php foreach ( $flow->steps as $i => $step ) : ?>
					<fieldset style="border:1px solid #ccd0d4;padding:12px;margin-bottom:12px">
						<legend><?php printf( \esc_html__( 'Step %d', 'cartquill' ), (int) $i + 1 ); ?></legend>
						<p>
							<label><?php echo \esc_html__( 'Delay (seconds)', 'cartquill' ); ?><br />
							<input type="number" min="0" name="steps[<?php echo (int) $i; ?>][delay]" value="<?php echo (int) $step->delay; ?>" /></label>
						</p>
						<p>
							<label style="display:block"><?php echo \esc_html__( 'Subject', 'cartquill' ); ?><br />
							<input type="text" class="large-text" name="steps[<?php echo (int) $i; ?>][subject]" value="<?php echo \esc_attr( $step->subject ); ?>" /></label>
						</p>
						<p>
							<label style="display:block"><?php echo \esc_html__( 'Body', 'cartquill' ); ?><br />
							<textarea class="large-text" rows="4" name="steps[<?php echo (int) $i; ?>][body]"><?php echo \esc_textarea( $step->body ); ?></textarea></label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="steps[<?php echo (int) $i; ?>][exit_if_ordered]" value="1" <?php \checked( $this->has_exit_condition( $step ) ); ?> />
								<?php echo \esc_html__( 'Exit this flow if the customer places an order', 'cartquill' ); ?>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="steps[<?php echo (int) $i; ?>][remove]" value="1" />
								<?php echo \esc_html__( 'Remove this step', 'cartquill' ); ?>
							</label>
						</p>
					</fieldset>
				<?php endforeach; ?>

				<p>
					<button type="submit" name="add_step" value="1" class="button"><?php echo \esc_html__( '+ Add step', 'cartquill' ); ?></button>
				</p>

				<?php \submit_button( \__( 'Save flow', 'cartquill' ) ); ?>
			</form>

			<?php
			/**
			 * After the editor form: add-ons attach per-step tools here (e.g. the
			 * AI add-on's "rewrite this step" controls). Rendered outside the main
			 * form so add-on actions post independently.
			 *
			 * @param FlowRecord $flow The flow being edited.
			 */
			\do_action( 'cartquill_flow_editor_after_form', $flow );
			?>
		</div>
		<?php
	}
}
