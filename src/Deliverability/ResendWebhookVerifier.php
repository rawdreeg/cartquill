<?php
/**
 * Verifies Resend (Svix) webhook signatures.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

/**
 * Resend signs webhooks with Svix: the signed content is
 * `{svix-id}.{svix-timestamp}.{body}` and the `svix-signature` header carries
 * one or more space-separated `v1,<base64 HMAC-SHA256>` values. The signing
 * secret is a `whsec_`-prefixed base64 key. We recompute the HMAC with the
 * store's own secret and accept the payload only on a constant-time match —
 * unsigned or tampered payloads never reach the processor.
 */
final class ResendWebhookVerifier implements WebhookVerifier {

	/** @var string Raw HMAC key bytes. */
	private readonly string $secret_bytes;

	public function __construct( string $signing_secret ) {
		$base64 = str_starts_with( $signing_secret, 'whsec_' )
			? substr( $signing_secret, 6 )
			: $signing_secret;

		$decoded            = base64_decode( $base64, true );
		$this->secret_bytes = false === $decoded ? '' : $decoded;
	}

	public function verify( string $payload, array $headers ): bool {
		$id        = $headers['svix-id'] ?? '';
		$timestamp = $headers['svix-timestamp'] ?? '';
		$signature = $headers['svix-signature'] ?? '';

		if ( '' === $this->secret_bytes || '' === $id || '' === $timestamp || '' === $signature ) {
			return false;
		}

		$signed   = $id . '.' . $timestamp . '.' . $payload;
		$expected = base64_encode( hash_hmac( 'sha256', $signed, $this->secret_bytes, true ) );

		// The header may list several space-separated `v1,<sig>` versions.
		foreach ( explode( ' ', $signature ) as $part ) {
			$pieces = explode( ',', $part, 2 );
			$candidate = 2 === count( $pieces ) ? $pieces[1] : $pieces[0];
			if ( '' !== $candidate && hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}

		return false;
	}
}
