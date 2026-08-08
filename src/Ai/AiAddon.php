<?php
/**
 * The AI Flow Generation add-on: wires itself in only when licensed.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Admin\AiGeneratePage;
use CartQuill\Flow\FlowLibrary;
use CartQuill\Licensing\License;
use CartQuill\Licensing\OptionLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\FlowRepository;

/**
 * Hooks `cartquill_register_addons` and only boots when the AI capability is
 * licensed — so the paid feature's admin surface and proxy client never load for
 * free-tier stores. Rate limiting keeps proxy usage (and cost) bounded.
 */
final class AiAddon {

	private const DEFAULT_LIMIT  = 20;
	private const DEFAULT_WINDOW = DAY_IN_SECONDS;

	/** Where the proxy's bearer key comes from; separate from the gate so tests can fake the gate. */
	private readonly OptionLicense $keys;

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly FlowLibrary $library,
		private readonly License $license,
		?OptionLicense $keys = null,
	) {
		$this->keys = $keys ?? new OptionLicense();
	}

	public function register(): void {
		\add_action( 'cartquill_register_addons', array( $this, 'boot' ) );
	}

	public function boot(): void {
		$license = $this->license;
		if ( ! $license->is_active( Plans::AI ) ) {
			return;
		}

		/** Max AI generations per rolling window. */
		$limit = (int) \apply_filters( 'cartquill_ai_rate_limit', self::DEFAULT_LIMIT );
		/** Rate-limit window length, in seconds. */
		$window = (int) \apply_filters( 'cartquill_ai_rate_window', self::DEFAULT_WINDOW );

		$limiter   = new TransientRateLimiter( $limit, $window );
		$generator = new AiFlowGenerator(
			new HttpProxyClient( $this->keys ),
			$this->flows,
			$limiter,
			$license,
		);

		$disclosure = new AiDisclosure();

		( new AiGeneratePage( $generator, $this->library, $disclosure, $limiter ) )->register();

		// Per-step rewrite lives in the builder: a REST endpoint plus this add-on's own
		// bundle, which fills the builder's `emailCopyAssist` slot with the control.
		$rewrite = new AiRewriteRestController( $generator, $disclosure );
		\add_action( 'rest_api_init', array( $rewrite, 'register_routes' ) );
		\add_action( 'cartquill_builder_enqueued', array( $this, 'enqueue_builder_bundle' ) );
	}

	/**
	 * Enqueue the add-on's builder bundle alongside the builder's own, so it can
	 * register its slot component before the builder mounts on DOMContentLoaded.
	 *
	 * @param string $version The builder bundle's cache-busting version.
	 */
	public function enqueue_builder_bundle( string $version ): void {
		\wp_enqueue_script(
			'cartquill-flow-builder-ai',
			\plugins_url( 'assets/builder/build/ai.js', CARTQUILL_FILE ),
			array( 'wp-element', 'wp-i18n' ),
			$version,
			true
		);
		\wp_set_script_translations( 'cartquill-flow-builder-ai', 'cartquill' );
	}
}
