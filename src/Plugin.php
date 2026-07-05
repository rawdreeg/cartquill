<?php
/**
 * Runtime bootstrap: wires the object graph and registers WordPress hooks.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Admin\FlowEditorPage;
use FlowForge\Admin\FlowLibraryPage;
use FlowForge\Admin\LicensePage;
use FlowForge\Admin\Onboarding;
use FlowForge\Admin\OnboardingPage;
use FlowForge\Admin\ReportingPage;
use FlowForge\Admin\SettingsPage;
use FlowForge\Licensing\OptionLicense;
use FlowForge\Security\InstallKey;
use FlowForge\Sender\SenderRegistry;
use FlowForge\Attribution\Attributor;
use FlowForge\Compliance\PersonalData;
use FlowForge\Compliance\PrivacyHooks;
use FlowForge\Compliance\UnsubscribeEndpoint;
use FlowForge\Compliance\UnsubscribeLink;
use FlowForge\Compliance\WpdbSuppressionList;
use FlowForge\Engine\ConditionEvaluator;
use FlowForge\Engine\Enroller;
use FlowForge\Engine\FlowTypeEnroller;
use FlowForge\Engine\MessageComposer;
use FlowForge\Engine\StepRunner;
use FlowForge\Engine\WooCustomerActivity;
use FlowForge\Flow\FlowEditor;
use FlowForge\Flow\FlowInstaller;
use FlowForge\Flow\FlowLibrary;
use FlowForge\Flow\Renderer;
use FlowForge\Engine\WooLapsedCustomerFinder;
use FlowForge\Integration\AbandonedCartScanner;
use FlowForge\Integration\AbandonedCartTracker;
use FlowForge\Integration\AttributionTrigger;
use FlowForge\Integration\PostPurchaseTrigger;
use FlowForge\Integration\WelcomeTrigger;
use FlowForge\Integration\WinBackScanner;
use FlowForge\Persistence\WpdbAttributionRepository;
use FlowForge\Persistence\WpdbCartCaptureStore;
use FlowForge\Persistence\WpdbEnrollmentRepository;
use FlowForge\Persistence\WpdbFlowRepository;
use FlowForge\Persistence\WpdbMessageRepository;
use FlowForge\Scheduling\ActionSchedulerScheduler;
use FlowForge\Sender\WpMailSender;
use FlowForge\Settings\OptionsSettings;
use FlowForge\Support\SystemClock;
use FlowForge\Tracking\SelfHostedLinkTracker;
use FlowForge\Tracking\Signer;
use FlowForge\Tracking\TrackingEndpoint;
use FlowForge\Tracking\TrackingUrls;

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
		$enrollments  = new WpdbEnrollmentRepository();
		$messages     = new WpdbMessageRepository();
		$captures     = new WpdbCartCaptureStore();
		$suppression  = new WpdbSuppressionList();
		$attributions = new WpdbAttributionRepository();
		$signer       = new Signer( InstallKey::get() );

		// Compliance must work even without WooCommerce: a customer must always
		// be able to opt out and privacy export/erase must still run, so these
		// register before the WooCommerce gate below.
		$unsubscribe_link = new UnsubscribeLink( \home_url( '/' ), $signer );
		( new UnsubscribeEndpoint( $signer, $unsubscribe_link, $suppression, $enrollments ) )->register();
		( new PrivacyHooks( new PersonalData( $enrollments, $messages, $captures, $suppression, $attributions ) ) )->register();

		if ( ! Activation::woocommerce_ready() ) {
			\add_action( 'admin_notices', array( $this, 'render_missing_woo_notice' ) );
			return;
		}

		$settings  = new OptionsSettings();
		$clock     = new SystemClock();
		$scheduler = new ActionSchedulerScheduler();
		$flows     = new WpdbFlowRepository();
		$activity  = new WooCustomerActivity();

		$tracking_urls = new TrackingUrls( \home_url( '/' ), $signer );
		( new TrackingEndpoint( $messages, $signer, $tracking_urls ) )->register();

		// Licensing + sender registry. Core ships wp_mail as the default sender.
		$license = new OptionLicense();
		( new LicensePage( $license ) )->register();

		// Paid add-ons ship separately (Freemius) and self-register on the
		// hooks fired below. This is a no-op in the free build, where their
		// directories are absent.
		$this->load_addons();

		$senders = new SenderRegistry( 'wp_mail' );
		$senders->register( new WpMailSender() );
		/**
		 * Sender add-ons register their transports here (license-gated).
		 *
		 * @param SenderRegistry $senders The sender registry.
		 * @param License        $license The licensing gate.
		 */
		\do_action( 'flowforge_register_senders', $senders, $license );
		$senders->set_active( (string) \apply_filters( 'flowforge_active_sender', 'wp_mail' ) );

		$library = new FlowLibrary();

		/**
		 * General add-on registration point (e.g. the AI Flow Generation add-on,
		 * which is not a sender). Add-ons check $license before wiring in.
		 *
		 * @param License $license The licensing gate.
		 */
		\do_action( 'flowforge_register_addons', $license );

		$runner = new StepRunner(
			$flows,
			$enrollments,
			$messages,
			new MessageComposer( new Renderer(), $settings, new SelfHostedLinkTracker( $tracking_urls ), $unsubscribe_link ),
			$senders->active(),
			$suppression,
			new ConditionEvaluator( $activity ),
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

		$enroller      = new Enroller( $enrollments, $scheduler, $clock );
		$type_enroller = new FlowTypeEnroller( $flows, $enroller );

		( new PostPurchaseTrigger( $type_enroller ) )->register();
		( new WelcomeTrigger( $type_enroller, $activity ) )->register();
		( new SettingsPage( $settings ) )->register();
		( new OnboardingPage( new Onboarding(), $settings ) )->register();

		( new FlowLibraryPage( $library, new FlowInstaller( $library, $flows ), $flows ) )->register();
		( new FlowEditorPage( $flows, new FlowEditor() ) )->register();

		( new AttributionTrigger( new Attributor( $messages, $attributions ) ) )->register();
		( new ReportingPage( $flows, $messages, $attributions, $license ) )->register();

		( new AbandonedCartTracker( $captures, $clock ) )->register();

		$cart_scanner = new AbandonedCartScanner( $captures, $flows, $enroller, $clock );
		\add_action(
			AbandonedCartScanner::HOOK,
			static function () use ( $cart_scanner ): void {
				/** Idle time before a cart counts as abandoned, in seconds. */
				$threshold = (int) \apply_filters( 'flowforge_abandoned_cart_threshold', AbandonedCartScanner::DEFAULT_THRESHOLD );
				$cart_scanner->scan( $threshold );
			}
		);

		$win_back = new WinBackScanner( new WooLapsedCustomerFinder(), $flows, $enrollments, $enroller, $clock );
		\add_action(
			WinBackScanner::HOOK,
			static function () use ( $win_back ): void {
				/** How long since the last order before a customer is lapsed, in seconds. */
				$threshold = (int) \apply_filters( 'flowforge_win_back_threshold', WinBackScanner::DEFAULT_THRESHOLD );
				$win_back->scan( $threshold );
			}
		);

		\add_action(
			'init',
			static function () use ( $clock ): void {
				self::schedule_recurring( AbandonedCartScanner::HOOK, AbandonedCartScanner::SCAN_INTERVAL, $clock );
				self::schedule_recurring( WinBackScanner::HOOK, WinBackScanner::SCAN_INTERVAL, $clock );
			}
		);
	}

	/**
	 * Include any installed add-on bootstrap files so they can self-register on
	 * the `flowforge_register_*` hooks before those hooks fire. Each paid add-on
	 * owns a `src/<Addon>/addon.php`; the free WP.org build omits those
	 * directories, so this loads whichever add-ons are actually present.
	 */
	private function load_addons(): void {
		foreach ( array( 'Ai', 'Deliverability' ) as $addon ) {
			$bootstrap = FLOWFORGE_PATH . 'src/' . $addon . '/addon.php';
			if ( is_readable( $bootstrap ) ) {
				require_once $bootstrap;
			}
		}
	}

	/**
	 * Ensure a recurring Action Scheduler action is scheduled (once).
	 */
	private static function schedule_recurring( string $hook, int $interval, \FlowForge\Support\Clock $clock ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! \as_has_scheduled_action( $hook ) ) {
			\as_schedule_recurring_action( $clock->now(), $interval, $hook, array(), 'flowforge' );
		}
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
