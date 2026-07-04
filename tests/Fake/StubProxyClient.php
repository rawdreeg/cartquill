<?php
/**
 * Scripted ProxyClient for tests — no network.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Fake;

use FlowForge\Ai\ProxyClient;
use FlowForge\Ai\ProxyException;

/**
 * Returns canned steps / rewrites and records the calls, standing in for the
 * hosted proxy so the generator can be tested without a network. Set
 * `$throw` to simulate a proxy failure.
 */
final class StubProxyClient implements ProxyClient {

	/** @var list<array{delay: int, subject: string, body: string, conditions?: array<int, mixed>}> */
	private array $steps;

	private bool $throw = false;

	/** @var list<array{flow_type: string, store_context: array<string, mixed>}> */
	public array $generate_calls = array();

	/** @var list<array{copy: string, instruction: string}> */
	public array $rewrite_calls = array();

	/**
	 * @param list<array{delay: int, subject: string, body: string, conditions?: array<int, mixed>}> $steps
	 */
	public function __construct( array $steps = array() ) {
		$this->steps = $steps;
	}

	public function fail(): void {
		$this->throw = true;
	}

	public function generate( string $flow_type, array $store_context ): array {
		$this->generate_calls[] = array(
			'flow_type'     => $flow_type,
			'store_context' => $store_context,
		);
		if ( $this->throw ) {
			throw new ProxyException( 'stub failure' );
		}
		return $this->steps;
	}

	public function rewrite( string $copy, string $instruction ): string {
		$this->rewrite_calls[] = array(
			'copy'        => $copy,
			'instruction' => $instruction,
		);
		if ( $this->throw ) {
			throw new ProxyException( 'stub failure' );
		}
		return $instruction . ': ' . $copy;
	}
}
