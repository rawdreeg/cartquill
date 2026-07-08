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

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly FlowLibrary $library,
		private readonly OptionLicense $license,
	) {}

	public function register(): void {
		\add_action( 'cartquill_register_addons', array( $this, 'boot' ) );
	}

	/**
	 * @param License $license The licensing gate passed by the registration hook.
	 */
	public function boot( License $license ): void {
		if ( ! $license->is_active( Plans::AI ) ) {
			return;
		}

		/** Max AI generations per rolling window. */
		$limit = (int) \apply_filters( 'cartquill_ai_rate_limit', self::DEFAULT_LIMIT );
		/** Rate-limit window length, in seconds. */
		$window = (int) \apply_filters( 'cartquill_ai_rate_window', self::DEFAULT_WINDOW );

		$limiter   = new TransientRateLimiter( $limit, $window );
		$generator = new AiFlowGenerator(
			new HttpProxyClient( $this->license ),
			$this->flows,
			$limiter,
			$license,
		);

		$disclosure = new AiDisclosure();

		( new AiGeneratePage( $generator, $this->library, $disclosure, $limiter ) )->register();
		( new AiRewriteController( $generator, $this->flows, $disclosure ) )->register();

		// The builder's per-step rewrite: a REST endpoint plus a feature flag telling the
		// React app the control is available. Both live behind the AI gate.
		$rewrite = new AiRewriteRestController( $generator, $disclosure );
		\add_action( 'rest_api_init', array( $rewrite, 'register_routes' ) );
		\add_filter(
			'cartquill_builder_config',
			static function ( array $config ): array {
				$config['features']              = (array) ( $config['features'] ?? array() );
				$config['features']['aiRewrite'] = true;
				return $config;
			}
		);
	}
}
