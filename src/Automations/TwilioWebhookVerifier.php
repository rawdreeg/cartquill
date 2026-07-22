<?php
/**
 * Verifies inbound Twilio webhook signatures.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Twilio signs each request with the account auth token: the signed content is
 * the full request URL followed by every POST parameter sorted by name and
 * concatenated as `name.value`, and the `X-Twilio-Signature` header carries the
 * base64 HMAC-SHA1 of that content. We recompute it with the store's own token
 * and accept only on a constant-time match — unsigned or tampered requests never
 * reach STOP/START handling.
 */
final class TwilioWebhookVerifier {

	public function __construct( private readonly string $auth_token ) {}

	/**
	 * @param array<string, string> $params The POST parameters Twilio sent.
	 */
	public function verify( string $url, array $params, string $signature ): bool {
		if ( '' === $this->auth_token || '' === $signature ) {
			return false;
		}

		ksort( $params );
		$data = $url;
		foreach ( $params as $key => $value ) {
			$data .= $key . $value;
		}

		$expected = base64_encode( hash_hmac( 'sha1', $data, $this->auth_token, true ) );

		return hash_equals( $expected, $signature );
	}
}
