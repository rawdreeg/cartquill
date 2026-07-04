<?php
/**
 * Svix signature verification for Resend webhooks.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Deliverability\ResendWebhookVerifier;
use PHPUnit\Framework\TestCase;

final class ResendWebhookVerifierTest extends TestCase {

	private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'; // sample base64 key.

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>
	 */
	private function signed_headers( string $payload, array $overrides = array() ): array {
		$id        = 'msg_2b';
		$timestamp = '1700000000';
		$key       = base64_decode( substr( self::SECRET, 6 ), true );
		$signature = base64_encode( hash_hmac( 'sha256', "{$id}.{$timestamp}.{$payload}", (string) $key, true ) );

		return array_merge(
			array(
				'svix-id'        => $id,
				'svix-timestamp' => $timestamp,
				'svix-signature' => 'v1,' . $signature,
			),
			$overrides
		);
	}

	public function test_accepts_a_correctly_signed_payload(): void {
		$verifier = new ResendWebhookVerifier( self::SECRET );
		$payload  = '{"type":"email.delivered"}';

		$this->assertTrue( $verifier->verify( $payload, $this->signed_headers( $payload ) ) );
	}

	public function test_rejects_a_tampered_payload(): void {
		$verifier = new ResendWebhookVerifier( self::SECRET );
		$headers  = $this->signed_headers( '{"type":"email.delivered"}' );

		$this->assertFalse( $verifier->verify( '{"type":"email.complained"}', $headers ), 'body no longer matches the signature' );
	}

	public function test_rejects_a_wrong_secret(): void {
		$payload  = '{"type":"email.delivered"}';
		$headers  = $this->signed_headers( $payload );
		$verifier = new ResendWebhookVerifier( 'whsec_' . base64_encode( 'a-different-secret' ) );

		$this->assertFalse( $verifier->verify( $payload, $headers ) );
	}

	public function test_rejects_missing_signature_headers(): void {
		$verifier = new ResendWebhookVerifier( self::SECRET );
		$payload  = '{"type":"email.delivered"}';

		$this->assertFalse( $verifier->verify( $payload, array() ), 'unsigned payloads are rejected' );
		$this->assertFalse( $verifier->verify( $payload, array( 'svix-id' => 'msg_2b' ) ), 'partial headers are rejected' );
	}

	public function test_accepts_when_header_lists_multiple_versions(): void {
		$verifier = new ResendWebhookVerifier( self::SECRET );
		$payload  = '{"type":"email.bounced"}';
		$headers  = $this->signed_headers( $payload );
		$headers['svix-signature'] = 'v1,AAAAdefinitelywrong v1,' . explode( ',', $headers['svix-signature'], 2 )[1];

		$this->assertTrue( $verifier->verify( $payload, $headers ), 'any listed signature matching is accepted' );
	}
}
