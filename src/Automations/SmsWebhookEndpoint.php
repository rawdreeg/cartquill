<?php
/**
 * Inbound Twilio SMS webhook (STOP/START handling).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Compliance\SuppressionList;

/**
 * Exposes a single URL (`?cartquill_sms_webhook=twilio`) Twilio POSTs inbound
 * texts to. It verifies the Twilio signature (locked rule: webhook signatures
 * verified), then honours opt-out keywords: STOP/UNSUBSCRIBE/CANCEL/END/QUIT add
 * the number to the SMS suppression list; START/UNSTOP/YES remove it. The
 * verify/handle logic is isolated in `handle()` so it can be reasoned about
 * without WordPress.
 */
final class SmsWebhookEndpoint {

	public const PARAM = 'cartquill_sms_webhook';

	private const STOP_KEYWORDS  = array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT' );
	private const START_KEYWORDS = array( 'START', 'UNSTOP', 'YES' );

	public function __construct(
		private readonly TwilioWebhookVerifier $verifier,
		private readonly SuppressionList $suppression,
	) {}

	public function register(): void {
		\add_action( 'init', array( $this, 'maybe_handle_request' ) );
	}

	/**
	 * Verify + handle one inbound message. Returns the HTTP status to respond with.
	 *
	 * @param array<string, string> $params The POST parameters Twilio sent.
	 */
	public function handle( string $url, array $params, string $signature ): int {
		if ( ! $this->verifier->verify( $url, $params, $signature ) ) {
			return 401;
		}

		$from = Phone::normalize( (string) ( $params['From'] ?? '' ) );
		if ( '' === $from ) {
			return 400;
		}

		$keyword = strtoupper( trim( (string) ( $params['Body'] ?? '' ) ) );
		if ( in_array( $keyword, self::STOP_KEYWORDS, true ) ) {
			$this->suppression->suppress( $from, 'sms_stop', SmsAction::TYPE );
		} elseif ( in_array( $keyword, self::START_KEYWORDS, true ) ) {
			$this->suppression->remove( $from, SmsAction::TYPE );
		}

		return 200;
	}

	public function maybe_handle_request(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$status = $this->handle( $this->request_url(), $this->read_params(), $this->signature() );

		\status_header( $status );
		// Twilio expects TwiML; an empty response acknowledges without auto-replying.
		echo 200 === $status ? '<?xml version="1.0" encoding="UTF-8"?><Response></Response>' : 'rejected';
		exit;
	}

	/**
	 * @return array<string, string>
	 */
	private function read_params(): array {
		$params = array();
		foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( is_scalar( $value ) ) {
				$params[ (string) $key ] = \sanitize_text_field( \wp_unslash( (string) $value ) );
			}
		}
		return $params;
	}

	private function signature(): string {
		return isset( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] )
			? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ) )
			: '';
	}

	/**
	 * The full URL Twilio signed (scheme + host + request URI).
	 */
	private function request_url(): string {
		$scheme = ( function_exists( 'is_ssl' ) && \is_ssl() ) ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return $scheme . '://' . $host . $uri;
	}
}
