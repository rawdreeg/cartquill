<?php
/**
 * Twilio signature verification + inbound STOP/START handling.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Automations\SmsAction;
use CartQuill\Automations\SmsWebhookEndpoint;
use CartQuill\Automations\TwilioWebhookVerifier;
use CartQuill\Compliance\ArraySuppressionList;
use PHPUnit\Framework\TestCase;

final class SmsWebhookTest extends TestCase {

	private const TOKEN = 'test-auth-token';
	private const URL   = 'https://shop.test/?cartquill_sms_webhook=twilio';

	/**
	 * @param array<string, string> $params
	 */
	private function sign( string $url, array $params ): string {
		ksort( $params );
		$data = $url;
		foreach ( $params as $k => $v ) {
			$data .= $k . $v;
		}
		return base64_encode( hash_hmac( 'sha1', $data, self::TOKEN, true ) );
	}

	public function test_verifier_accepts_a_correct_signature_and_rejects_others(): void {
		$verifier = new TwilioWebhookVerifier( self::TOKEN );
		$params   = array( 'From' => '+15551112222', 'Body' => 'STOP' );

		$this->assertTrue( $verifier->verify( self::URL, $params, $this->sign( self::URL, $params ) ) );
		$this->assertFalse( $verifier->verify( self::URL, $params, 'not-the-signature' ) );
		$this->assertFalse( $verifier->verify( self::URL, array( 'From' => '+15551112222', 'Body' => 'START' ), $this->sign( self::URL, $params ) ), 'tampered body no longer matches' );
		$this->assertFalse( $verifier->verify( self::URL, $params, '' ), 'missing signature rejected' );
	}

	public function test_valid_stop_suppresses_the_number_on_the_sms_channel(): void {
		$suppression = new ArraySuppressionList();
		$endpoint    = new SmsWebhookEndpoint( new TwilioWebhookVerifier( self::TOKEN ), $suppression );
		$params      = array( 'From' => '+15551112222', 'Body' => 'Stop' );

		$status = $endpoint->handle( self::URL, $params, $this->sign( self::URL, $params ) );

		$this->assertSame( 200, $status );
		$this->assertTrue( $suppression->is_suppressed( '+15551112222', SmsAction::TYPE ), 'STOP suppresses on the sms_send channel' );
		$this->assertFalse( $suppression->is_suppressed( '+15551112222' ), 'the email channel is untouched' );
	}

	public function test_valid_start_removes_the_suppression(): void {
		$suppression = new ArraySuppressionList();
		$suppression->suppress( '+15551112222', 'sms_stop', SmsAction::TYPE );
		$endpoint = new SmsWebhookEndpoint( new TwilioWebhookVerifier( self::TOKEN ), $suppression );
		$params   = array( 'From' => '+15551112222', 'Body' => 'START' );

		$status = $endpoint->handle( self::URL, $params, $this->sign( self::URL, $params ) );

		$this->assertSame( 200, $status );
		$this->assertFalse( $suppression->is_suppressed( '+15551112222', SmsAction::TYPE ), 'START opts the number back in' );
	}

	public function test_invalid_signature_is_rejected_and_changes_nothing(): void {
		$suppression = new ArraySuppressionList();
		$endpoint    = new SmsWebhookEndpoint( new TwilioWebhookVerifier( self::TOKEN ), $suppression );

		$status = $endpoint->handle( self::URL, array( 'From' => '+15551112222', 'Body' => 'STOP' ), 'forged-signature' );

		$this->assertSame( 401, $status );
		$this->assertFalse( $suppression->is_suppressed( '+15551112222', SmsAction::TYPE ), 'an unsigned STOP cannot suppress' );
	}
}
