<?php
/**
 * Runtime bootstrap: wires the object graph and registers WordPress hooks.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge;

use FlowForge\Admin\SettingsPage;
use FlowForge\Engine\SpineDispatcher;
use FlowForge\Flow\Renderer;
use FlowForge\Integration\WooOrderTrigger;
use FlowForge\Persistence\WpdbEnrollmentRepository;
use FlowForge\Persistence\WpdbMessageRepository;
use FlowForge\Settings\OptionsSettings;
use FlowForge\Support\SystemClock;

/**
 * Composition root. Kept thin: it assembles the tested core (dispatcher,
 * repositories, senders) with their WordPress-backed implementations and hooks
 * them in. If WooCommerce is not active at runtime it shows a notice and does
 * not boot the engine.
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

		$settings = new OptionsSettings();

		$dispatcher = new SpineDispatcher(
			new \FlowForge\Sender\WpMailSender(),
			new WpdbMessageRepository(),
			new Renderer(),
			$settings,
			new SystemClock(),
		);

		( new WooOrderTrigger( $dispatcher, new WpdbEnrollmentRepository() ) )->register();
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
