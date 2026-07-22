<?php
/**
 * Contract with the vendor's license-gated AI proxy.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The seam between the add-on and the hosted proxy. The proxy holds the LLM keys
 * server-side and is seeded with the curated, high-converting WooCommerce flow
 * library (the moat) — so this client only sends store context + a flow type and
 * receives grounded steps back. The proxy backend itself is out of scope; this
 * is its contract, stubbed in tests.
 */
interface ProxyClient {

	/**
	 * Generate ordered steps for a flow type given store context.
	 *
	 * @param string                                                                                  $flow_type     e.g. "abandoned_cart".
	 * @param array<string, mixed>                                                                    $store_context Store name, products, tone, …
	 * @param list<array{delay: int, subject: string, body: string, conditions?: array<int, mixed>}> $seed_steps    Curated starter template to personalize; empty means none.
	 *
	 * @return list<array{delay: int, subject: string, body: string, conditions?: array<int, mixed>}>
	 *
	 * @throws ProxyException On transport failure or a proxy error.
	 */
	public function generate( string $flow_type, array $store_context, array $seed_steps = array() ): array;

	/**
	 * Rewrite/vary a single piece of copy.
	 *
	 * @throws ProxyException On transport failure or a proxy error.
	 */
	public function rewrite( string $copy, string $instruction ): string;
}
