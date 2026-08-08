<?php
/**
 * Runtime bootstrap: wires the object graph and registers WordPress hooks.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Admin\AdminAssets;
use CartQuill\Admin\FlowBuilderPage;
use CartQuill\Admin\FlowLibraryPage;
use CartQuill\Admin\Onboarding;
use CartQuill\Admin\OnboardingPage;
use CartQuill\Admin\ReportingPage;
use CartQuill\Admin\SettingsPage;
use CartQuill\Action\ActionRegistry;
use CartQuill\Metering\Meter;
use CartQuill\Metering\NullMeter;
use CartQuill\Security\InstallKey;
use CartQuill\Security\SodiumCrypto;
use CartQuill\Builder\CatalogFactory;
use CartQuill\Builder\FlowSerializer;
use CartQuill\Builder\FlowValidator;
use CartQuill\Persistence\EncryptedCredentials;
use CartQuill\Persistence\WpdbConnectionStore;
use CartQuill\Rest\FlowBuilderController;
use CartQuill\Sender\SenderRegistry;
use CartQuill\Attribution\Attributor;
use CartQuill\Compliance\PersonalData;
use CartQuill\Compliance\PrivacyHooks;
use CartQuill\Compliance\UnsubscribeEndpoint;
use CartQuill\Compliance\UnsubscribeLink;
use CartQuill\Compliance\WpdbSuppressionList;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\FlowTypeEnroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Engine\WooCustomerActivity;
use CartQuill\Flow\FlowInstaller;
use CartQuill\Flow\FlowLibrary;
use CartQuill\Flow\Renderer;
use CartQuill\Engine\WooLapsedCustomerFinder;
use CartQuill\Integration\AbandonedCartScanner;
use CartQuill\Integration\AbandonedCartTracker;
use CartQuill\Integration\AttributionTrigger;
use CartQuill\Integration\PostPurchaseTrigger;
use CartQuill\Integration\WelcomeTrigger;
use CartQuill\Integration\WinBackScanner;
use CartQuill\Persistence\WpdbAttributionRepository;
use CartQuill\Persistence\WpdbCartCaptureStore;
use CartQuill\Persistence\WpdbEnrollmentRepository;
use CartQuill\Persistence\WpdbFlowRepository;
use CartQuill\Persistence\WpdbMessageRepository;
use CartQuill\Scheduling\ActionSchedulerScheduler;
use CartQuill\Sender\WpMailSender;
use CartQuill\Settings\OptionsSettings;
use CartQuill\Support\OptionScanCursor;
use CartQuill\Support\SystemClock;
use CartQuill\Tracking\SelfHostedLinkTracker;
use CartQuill\Tracking\Signer;
use CartQuill\Tracking\TrackingEndpoint;
use CartQuill\Tracking\TrackingUrls;

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

		// Registered this early so the top-level 'cartquill' menu always exists
		// on the admin_menu hook before anything else runs — core pages and
		// add-on-contributed ones alike. add_submenu_page() resolves the wrong
		// page hook (landing on "Sorry, you are not allowed to access this
		// page") if it runs before the parent's own add_menu_page() call within
		// the same admin_menu cycle, and add-ons hook their surfaces in well
		// before this method reaches its own admin-page registrations below.
		( new SettingsPage( $settings ) )->register();

		$tracking_urls = new TrackingUrls( \home_url( '/' ), $signer );
		( new TrackingEndpoint( $messages, $signer, $tracking_urls ) )->register();

		// Extensions ship separately and self-register on the hooks fired below.
		// This is a no-op when none is installed.
		$this->load_addons();

		$senders = new SenderRegistry( 'wp_mail' );
		$senders->register( new WpMailSender() );
		/**
		 * Extensions register additional sending transports here.
		 *
		 * @param SenderRegistry $senders The sender registry.
		 */
		\do_action( 'cartquill_register_senders', $senders );
		$senders->set_active( (string) \apply_filters( 'cartquill_active_sender', 'wp_mail' ) );

		// Action registry: the multi-tool step layer. The core `email` action is
		// always available (the step runner builds it from the composer + active
		// sender below); an extension's actions self-register here.
		$actions = new ActionRegistry();
		/**
		 * Extensions register additional step actions here.
		 *
		 * @param ActionRegistry $actions The action registry.
		 */
		\do_action( 'cartquill_register_actions', $actions );

		$library = new FlowLibrary();

		/**
		 * General extension registration point, for anything that is neither a
		 * sender nor a step action.
		 */
		\do_action( 'cartquill_register_addons' );

		/**
		 * The execution policy the engine consults before running a step and after
		 * one succeeds. Core supplies a no-op that never defers anything; an
		 * extension can supply its own.
		 *
		 * @param Meter $meter A no-op meter.
		 */
		$meter = \apply_filters( 'cartquill_meter', new NullMeter() );
		if ( ! $meter instanceof Meter ) {
			$meter = new NullMeter();
		}

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
			$actions,
			$meter,
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

		$enroller      = new Enroller( $enrollments, $scheduler, $clock, $meter );
		$type_enroller = new FlowTypeEnroller( $flows, $enroller );

		( new PostPurchaseTrigger( $type_enroller ) )->register();
		( new WelcomeTrigger( $type_enroller, $activity ) )->register();
		( new AdminAssets() )->register();
		( new OnboardingPage( new Onboarding(), $settings ) )->register();

		( new FlowLibraryPage( $library, new FlowInstaller( $library, $flows ), $flows ) )->register();
		( new FlowBuilderPage() )->register();

		( new AttributionTrigger( new Attributor( $messages, $attributions ) ) )->register();
		( new ReportingPage( $flows, $messages, $attributions ) )->register();

		// Builder REST API (cartquill/v1): the catalog + stored flows the React
		// builder loads and writes. Built at rest_api_init so the catalog (which folds
		// in extension contributions via CatalogFactory) is only assembled for REST
		// requests, after anything registered on load_addons() above.
		$connections = new WpdbConnectionStore( new EncryptedCredentials( new SodiumCrypto( InstallKey::get() ) ) );
		\add_action(
			'rest_api_init',
			static function () use ( $flows, $connections ): void {
				$catalog = CatalogFactory::create( $connections );
				( new FlowBuilderController(
					$flows,
					$catalog,
					new FlowSerializer(),
					new FlowValidator( $catalog )
				) )->register_routes();
			}
		);

		( new AbandonedCartTracker( $captures, $clock ) )->register();

		$cart_scanner = new AbandonedCartScanner( $captures, $flows, $enroller, $clock );
		\add_action(
			AbandonedCartScanner::HOOK,
			static function () use ( $cart_scanner ): void {
				/** Idle time before a cart counts as abandoned, in seconds. */
				$threshold = (int) \apply_filters( 'cartquill_abandoned_cart_threshold', AbandonedCartScanner::DEFAULT_THRESHOLD );
				$cart_scanner->scan( $threshold );
			}
		);

		$win_back = new WinBackScanner(
			new WooLapsedCustomerFinder(),
			$flows,
			$enrollments,
			$enroller,
			$clock,
			new OptionScanCursor( WinBackScanner::CURSOR_OPTION )
		);
		\add_action(
			WinBackScanner::HOOK,
			static function () use ( $win_back ): void {
				/** How long since the last order before a customer is lapsed, in seconds. */
				$threshold = (int) \apply_filters( 'cartquill_win_back_threshold', WinBackScanner::DEFAULT_THRESHOLD );
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
	 * Include any installed extension bootstrap file so it can self-register on the
	 * `cartquill_register_*` hooks before those hooks fire. An extension owns a
	 * `src/<Name>/addon.php`; this plugin ships none, so the loop is a no-op unless
	 * a separately distributed extension has been installed over it.
	 */
	private function load_addons(): void {
		foreach ( array( 'Licensing', 'Ai', 'Automations' ) as $addon ) {
			$bootstrap = CARTQUILL_PATH . 'src/' . $addon . '/addon.php';
			if ( is_readable( $bootstrap ) ) {
				require_once $bootstrap;
			}
		}
	}

	/**
	 * Ensure a recurring Action Scheduler action is scheduled (once).
	 */
	private static function schedule_recurring( string $hook, int $interval, \CartQuill\Support\Clock $clock ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! \as_has_scheduled_action( $hook ) ) {
			\as_schedule_recurring_action( $clock->now(), $interval, $hook, array(), 'cartquill' );
		}
	}

	public function render_missing_woo_notice(): void {
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo \esc_html__(
			'CartQuill is inactive because WooCommerce 8.0+ is not active. Activate WooCommerce to resume your flows.',
			'cartquill'
		);
		echo '</p></div>';
	}
}
