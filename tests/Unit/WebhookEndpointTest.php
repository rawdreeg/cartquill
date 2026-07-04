<?php
/**
 * The webhook endpoint's verify → parse → process decision, exercised through
 * its WordPress-free handle() seam.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Compliance\ArraySuppressionList;
use FlowForge\Deliverability\ResendWebhookVerifier;
use FlowForge\Deliverability\WebhookEndpoint;
use FlowForge\Deliverability\WebhookProcessor;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use PHPUnit\Framework\TestCase;

final class WebhookEndpointTest extends TestCase {

	private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

	private InMemoryMessageRepository $messages;
	private ArraySuppressionList $suppression;
	private WebhookEndpoint $endpoint;

	protected function setUp(): void {
		$this->messages    = new InMemoryMessageRepository();
		$this->suppression = new ArraySuppressionList();
		$this->endpoint    = new WebhookEndpoint(
			new ResendWebhookVerifier( self::SECRET ),
			new WebhookProcessor( $this->messages, $this->suppression ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function signed_headers( string $body ): array {
		$id  = 'msg_1';
		$ts  = '1700000000';
		$key = base64_decode( substr( self::SECRET, 6 ), true );
		return array(
			'svix-id'        => $id,
			'svix-timestamp' => $ts,
			'svix-signature' => 'v1,' . base64_encode( hash_hmac( 'sha256', "{$id}.{$ts}.{$body}", (string) $key, true ) ),
		);
	}

	public function test_valid_signed_event_is_processed_and_returns_200(): void {
		$this->messages->save(
			new MessageRecord( null, 1, 5, 0, 'dead@example.com', 'resend', MessageRecord::STATUS_SENT, 'resend-1', '2026-07-01 00:00:00' )
		);
		$body = '{"type":"email.bounced","data":{"email_id":"resend-1","to":["dead@example.com"]}}';

		$status = $this->endpoint->handle( $body, $this->signed_headers( $body ) );

		$this->assertSame( 200, $status );
		$this->assertSame( MessageRecord::STATUS_BOUNCED, $this->messages->all()[0]->status );
		$this->assertTrue( $this->suppression->is_suppressed( 'dead@example.com' ) );
	}

	public function test_unsigned_request_is_rejected_and_not_processed(): void {
		$this->messages->save(
			new MessageRecord( null, 1, 5, 0, 'buyer@example.com', 'resend', MessageRecord::STATUS_SENT, 'resend-2', '2026-07-01 00:00:00' )
		);
		$body = '{"type":"email.delivered","data":{"email_id":"resend-2"}}';

		$status = $this->endpoint->handle( $body, array() );

		$this->assertSame( 401, $status );
		$this->assertSame( MessageRecord::STATUS_SENT, $this->messages->all()[0]->status, 'unsigned payload never touches state' );
	}

	public function test_tampered_body_is_rejected(): void {
		$signed = '{"type":"email.delivered","data":{"email_id":"resend-3"}}';

		$status = $this->endpoint->handle( '{"type":"email.complained","data":{"email_id":"resend-3"}}', $this->signed_headers( $signed ) );

		$this->assertSame( 401, $status );
	}

	public function test_signed_but_malformed_json_returns_400(): void {
		$body = 'not-json';

		$status = $this->endpoint->handle( $body, $this->signed_headers( $body ) );

		$this->assertSame( 400, $status );
	}
}
