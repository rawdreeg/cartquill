<?php
/**
 * Generates draft flows from the license-gated AI proxy.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

use FlowForge\Flow\FlowStep;
use FlowForge\Licensing\License;
use FlowForge\Licensing\Plans;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\FlowRepository;

/**
 * Orchestrates one AI generation: gate on the AI license, spend the rate-limit
 * allowance, ask the proxy for grounded steps, then persist them as a DRAFT
 * flow. It NEVER creates an active flow — generated copy always lands in the
 * editor (#9) for review, so the engine can never auto-send AI output.
 */
final class AiFlowGenerator {

	public function __construct(
		private readonly ProxyClient $proxy,
		private readonly FlowRepository $flows,
		private readonly RateLimiter $rate_limiter,
		private readonly License $license,
	) {}

	/**
	 * @param array<string, mixed> $store_context Store name, products, tone, …
	 */
	public function generate( string $flow_type, array $store_context ): GenerationResult {
		if ( ! $this->license->is_active( Plans::AI ) ) {
			return GenerationResult::not_licensed();
		}
		if ( ! $this->rate_limiter->try_consume() ) {
			return GenerationResult::rate_limited();
		}

		try {
			$raw_steps = $this->proxy->generate( $flow_type, $store_context );
		} catch ( ProxyException $e ) {
			return GenerationResult::error( $e->getMessage() );
		}

		$steps = array_values( array_map(
			static fn( array $step ): FlowStep => FlowStep::from_array( $step ),
			$raw_steps,
		) );

		$record = new FlowRecord(
			null,
			$this->name_for( $flow_type, $store_context ),
			$flow_type,
			FlowRecord::STATUS_DRAFT,
			FlowRecord::SOURCE_AI,
			$steps,
		);

		return GenerationResult::ok( $this->flows->save( $record ) );
	}

	/**
	 * Rewrite/vary a single piece of copy. Returns null when unlicensed,
	 * rate-limited, or the proxy fails — callers keep the original copy.
	 */
	public function rewrite( string $copy, string $instruction ): ?string {
		if ( ! $this->license->is_active( Plans::AI ) ) {
			return null;
		}
		if ( ! $this->rate_limiter->try_consume() ) {
			return null;
		}
		try {
			return $this->proxy->rewrite( $copy, $instruction );
		} catch ( ProxyException $e ) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $store_context
	 */
	private function name_for( string $flow_type, array $store_context ): string {
		$label = ucwords( str_replace( array( '_', '-' ), ' ', $flow_type ) );
		$store = isset( $store_context['store_name'] ) ? (string) $store_context['store_name'] : '';
		return '' !== $store ? "{$store} — {$label} (AI)" : "{$label} (AI)";
	}
}
