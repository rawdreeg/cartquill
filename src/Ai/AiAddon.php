<?php
/**
 * The AI Flow Generation add-on: wires itself in only when licensed.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Admin\AiGeneratePage;
use FlowForge\Flow\FlowLibrary;
use FlowForge\Licensing\License;
use FlowForge\Licensing\OptionLicense;
use FlowForge\Licensing\Plans;
use FlowForge\Persistence\FlowRepository;

/**
 * Hooks `flowforge_register_addons` and only boots when the AI capability is
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
		\add_action( 'flowforge_register_addons', array( $this, 'boot' ) );
	}

	/**
	 * @param License $license The licensing gate passed by the registration hook.
	 */
	public function boot( License $license ): void {
		if ( ! $license->is_active( Plans::AI ) ) {
			return;
		}

		/** Max AI generations per rolling window. */
		$limit = (int) \apply_filters( 'flowforge_ai_rate_limit', self::DEFAULT_LIMIT );
		/** Rate-limit window length, in seconds. */
		$window = (int) \apply_filters( 'flowforge_ai_rate_window', self::DEFAULT_WINDOW );

		$generator = new AiFlowGenerator(
			new HttpProxyClient( $this->license ),
			$this->flows,
			new TransientRateLimiter( $limit, $window ),
			$license,
		);

		$disclosure = new AiDisclosure();

		( new AiGeneratePage( $generator, $this->library, $disclosure ) )->register();
		( new AiRewriteController( $generator, $this->flows, $disclosure ) )->register();
	}
}
