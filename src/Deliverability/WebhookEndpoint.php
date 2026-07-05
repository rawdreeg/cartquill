<?php
/**
 * Inbound Resend webhook endpoint.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Exposes a single URL (`?cartquill_webhook=resend`) that Resend POSTs delivery
 * events to. It reads the raw body + Svix headers, rejects anything the
 * {@see WebhookVerifier} can't authenticate (locked rule: signatures verified),
 * then hands the decoded event to the {@see WebhookProcessor}. The verify/parse
 * logic is isolated in `handle()` so it can be reasoned about without WordPress.
 */
final class WebhookEndpoint {

	public const PARAM = 'cartquill_webhook';

	public function __construct(
		private readonly WebhookVerifier $verifier,
		private readonly WebhookProcessor $processor,
	) {}

	public function register(): void {
		\add_action( 'init', array( $this, 'maybe_handle_request' ) );
	}

	/**
	 * Verify + process one webhook. Returns the HTTP status to respond with.
	 *
	 * @param array<string, string> $headers Lower-cased header names.
	 */
	public function handle( string $body, array $headers ): int {
		if ( ! $this->verifier->verify( $body, $headers ) ) {
			return 401;
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) {
			return 400;
		}

		$this->processor->process( $event );
		return 200;
	}

	public function maybe_handle_request(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$status = $this->handle( $this->read_body(), $this->read_headers() );

		\status_header( $status );
		echo 200 === $status ? 'ok' : 'rejected';
		exit;
	}

	private function read_body(): string {
		return (string) file_get_contents( 'php://input' );
	}

	/**
	 * @return array<string, string>
	 */
	private function read_headers(): array {
		$headers = array();
		foreach ( array( 'svix-id', 'svix-timestamp', 'svix-signature' ) as $name ) {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
			if ( isset( $_SERVER[ $key ] ) ) {
				$headers[ $name ] = \sanitize_text_field( \wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		return $headers;
	}
}
