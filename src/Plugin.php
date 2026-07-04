<?php
/**
 * Runtime bootstrap: wires the object graph and registers WordPress hooks.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge;

use FlowForge\Admin\SettingsPage;
use FlowForge\Compliance\WpdbSuppressionList;
use FlowForge\Engine\ConditionEvaluator;
use FlowForge\Engine\Enroller;
use FlowForge\Engine\MessageComposer;
use FlowForge\Engine\StepRunner;
use FlowForge\Engine\WooCustomerActivity;
use FlowForge\Flow\Renderer;
use FlowForge\Integration\WooOrderTrigger;
use FlowForge\Persistence\WpdbEnrollmentRepository;
use FlowForge\Persistence\WpdbFlowRepository;
use FlowForge\Persistence\WpdbMessageRepository;
use FlowForge\Scheduling\ActionSchedulerScheduler;
use FlowForge\Sender\WpMailSender;
use FlowForge\Settings\OptionsSettings;
use FlowForge\Support\SystemClock;

/**
 * Composition root. Kept thin: it assembles the tested engine core with its
 * WordPress-backed implementations, wires the Action Scheduler hook to the
 * step runner, and registers triggers. If WooCommerce is not active at runtime
 * it shows a notice and does not boot the engine.
 */
final class Plugin {

	private static ?self $instance = null;

	private function __construct() {}

	public static function boot(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
		return self::$instance;
	}

	private function register(): void {
		if ( ! Activation::woocommerce_ready() ) {
			\add_action( 'admin_notices', array( $this, 'render_missing_woo_notice' ) );
			return;
		}

		$settings    = new OptionsSettings();
		$clock       = new SystemClock();
		$scheduler   = new ActionSchedulerScheduler();
		$flows       = new WpdbFlowRepository();
		$enrollments = new WpdbEnrollmentRepository();
		$messages    = new WpdbMessageRepository();

		$runner = new StepRunner(
			$flows,
			$enrollments,
			$messages,
			new MessageComposer( new Renderer(), $settings ),
			new WpMailSender(),
			new WpdbSuppressionList(),
			new ConditionEvaluator( new WooCustomerActivity() ),
			$scheduler,
			$clock,
		);

		// Action Scheduler drives every delayed step through this hook.
		\add_action(
			ActionSchedulerScheduler::HOOK,
			static function ( $enrollment_id, $step_index ) use ( $runner ): void {
				$runner->run_step( (int) $enrollment_id, (int) $step_index );
			},
			10,
			2
		);

		$enroller = new Enroller( $enrollments, $scheduler, $clock );

		( new WooOrderTrigger( $enroller, $flows ) )->register();
		( new SettingsPage( $settings ) )->register();
	}

	public function render_missing_woo_notice(): void {
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo \esc_html__(
			'FlowForge is inactive because WooCommerce 8.0+ is not active. Activate WooCommerce to resume your flows.',
			'flowforge'
		);
		echo '</p></div>';
	}
}
