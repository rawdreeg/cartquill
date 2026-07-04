<?php
/**
 * HTTP-backed ProxyClient (runtime): talks to the vendor's license-gated proxy.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

use FlowForge\Licensing\OptionLicense;
use FlowForge\Licensing\Plans;

/**
 * Posts store context + flow type to the hosted proxy over HTTPS, authenticated
 * with the store's AI entitlement key. The proxy holds the LLM credentials and
 * the curated flow library; this client only marshals request/response and
 * normalises errors into {@see ProxyException}.
 *
 * The production endpoint is a human decision — it defaults to a vendor URL that
 * the `flowforge_ai_proxy_url` filter overrides for staging/self-hosting.
 */
final class HttpProxyClient implements ProxyClient {

	private const DEFAULT_ENDPOINT = 'https://proxy.flowforge.app/v1';

	public function __construct(
		private readonly OptionLicense $license,
		private readonly int $timeout = 20,
	) {}

	public function generate( string $flow_type, array $store_context ): array {
		$data = $this->post(
			'generate',
			array(
				'flow_type'     => $flow_type,
				'store_context' => $store_context,
			),
		);

		if ( ! isset( $data['steps'] ) || ! is_array( $data['steps'] ) ) {
			throw new ProxyException( 'Proxy response did not contain steps.' );
		}

		return array_values( array_map(
			static function ( $step ): array {
				return array(
					'delay'      => (int) ( $step['delay'] ?? 0 ),
					'subject'    => (string) ( $step['subject'] ?? '' ),
					'body'       => (string) ( $step['body'] ?? '' ),
					'conditions' => (array) ( $step['conditions'] ?? array() ),
				);
			},
			$data['steps'],
		) );
	}

	public function rewrite( string $copy, string $instruction ): string {
		$data = $this->post(
			'rewrite',
			array(
				'copy'        => $copy,
				'instruction' => $instruction,
			),
		);

		if ( ! isset( $data['copy'] ) ) {
			throw new ProxyException( 'Proxy response did not contain rewritten copy.' );
		}

		return (string) $data['copy'];
	}

	/**
	 * @param array<string, mixed> $body
	 *
	 * @return array<string, mixed>
	 */
	private function post( string $path, array $body ): array {
		$response = \wp_remote_post(
			$this->endpoint() . '/' . $path,
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->license->key_for( Plans::AI ),
				),
				'body'    => (string) \wp_json_encode( $body ),
			),
		);

		if ( \is_wp_error( $response ) ) {
			throw new ProxyException( 'AI proxy request failed: ' . $response->get_error_message() );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new ProxyException( "AI proxy returned HTTP {$code}." );
		}

		$decoded = json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			throw new ProxyException( 'AI proxy returned an unreadable response.' );
		}

		return $decoded;
	}

	private function endpoint(): string {
		/**
		 * Override the AI proxy base URL (staging / self-hosting).
		 *
		 * @param string $endpoint Default vendor proxy base URL.
		 */
		return rtrim( (string) \apply_filters( 'flowforge_ai_proxy_url', self::DEFAULT_ENDPOINT ), '/' );
	}
}
