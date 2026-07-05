<?php
/**
 * Webhook ingestion: representative Resend payloads → message status
 * transitions and suppression-list changes (payload-in / state-out).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Deliverability\WebhookProcessor;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Persistence\MessageRecord;
use PHPUnit\Framework\TestCase;

final class WebhookProcessorTest extends TestCase {

	private InMemoryMessageRepository $messages;
	private ArraySuppressionList $suppression;
	private WebhookProcessor $processor;

	protected function setUp(): void {
		$this->messages    = new InMemoryMessageRepository();
		$this->suppression = new ArraySuppressionList();
		$this->processor   = new WebhookProcessor( $this->messages, $this->suppression );
	}

	private function sent_message( string $external_id, string $recipient = 'buyer@example.com' ): MessageRecord {
		return $this->messages->save(
			new MessageRecord( null, 1, 5, 0, $recipient, 'resend', MessageRecord::STATUS_SENT, $external_id, '2026-07-01 00:00:00' )
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function event( string $type, array $data ): array {
		return array( 'type' => $type, 'data' => $data );
	}

	public function test_delivered_event_marks_the_message_delivered(): void {
		$message = $this->sent_message( 'resend-1' );

		$handled = $this->processor->process( $this->event( 'email.delivered', array( 'email_id' => 'resend-1' ) ) );

		$this->assertTrue( $handled );
		$this->assertSame( MessageRecord::STATUS_DELIVERED, $this->messages->find( (int) $message->id )->status );
	}

	public function test_opened_then_clicked_advance_the_status(): void {
		$message = $this->sent_message( 'resend-2' );

		$this->processor->process( $this->event( 'email.delivered', array( 'email_id' => 'resend-2' ) ) );
		$this->processor->process( $this->event( 'email.opened', array( 'email_id' => 'resend-2' ) ) );
		$this->assertSame( MessageRecord::STATUS_OPENED, $this->messages->find( (int) $message->id )->status );

		$this->processor->process( $this->event( 'email.clicked', array( 'email_id' => 'resend-2' ) ) );
		$this->assertSame( MessageRecord::STATUS_CLICKED, $this->messages->find( (int) $message->id )->status );
	}

	public function test_status_never_regresses_on_out_of_order_events(): void {
		$message = $this->sent_message( 'resend-3' );

		$this->processor->process( $this->event( 'email.clicked', array( 'email_id' => 'resend-3' ) ) );
		// A delivered event arriving late must not downgrade a clicked message.
		$this->processor->process( $this->event( 'email.delivered', array( 'email_id' => 'resend-3' ) ) );

		$this->assertSame( MessageRecord::STATUS_CLICKED, $this->messages->find( (int) $message->id )->status );
	}

	public function test_bounce_marks_bounced_and_suppresses_the_recipient(): void {
		$message = $this->sent_message( 'resend-4', 'dead@example.com' );

		$this->processor->process( $this->event( 'email.bounced', array( 'email_id' => 'resend-4', 'to' => array( 'dead@example.com' ) ) ) );

		$this->assertSame( MessageRecord::STATUS_BOUNCED, $this->messages->find( (int) $message->id )->status );
		$this->assertTrue( $this->suppression->is_suppressed( 'dead@example.com' ), 'bounced address is suppressed' );
	}

	public function test_complaint_marks_complained_and_suppresses_even_over_a_click(): void {
		$message = $this->sent_message( 'resend-5', 'angry@example.com' );
		$this->processor->process( $this->event( 'email.clicked', array( 'email_id' => 'resend-5' ) ) );

		$this->processor->process( $this->event( 'email.complained', array( 'email_id' => 'resend-5' ) ) );

		$this->assertSame( MessageRecord::STATUS_COMPLAINED, $this->messages->find( (int) $message->id )->status );
		$this->assertTrue( $this->suppression->is_suppressed( 'angry@example.com' ) );
	}

	public function test_bounce_for_unknown_message_still_suppresses_from_payload(): void {
		$handled = $this->processor->process(
			$this->event( 'email.bounced', array( 'email_id' => 'never-seen', 'to' => 'ghost@example.com' ) )
		);

		$this->assertTrue( $handled );
		$this->assertTrue( $this->suppression->is_suppressed( 'ghost@example.com' ), 'suppress even without a matching message row' );
	}

	public function test_a_late_bounce_never_downgrades_a_complaint(): void {
		$message = $this->sent_message( 'resend-8', 'angry@example.com' );
		$this->processor->process( $this->event( 'email.complained', array( 'email_id' => 'resend-8' ) ) );
		$this->assertSame( MessageRecord::STATUS_COMPLAINED, $this->messages->find( (int) $message->id )->status );

		// A bounce arriving after the complaint must not overwrite the terminal status.
		$this->processor->process( $this->event( 'email.bounced', array( 'email_id' => 'resend-8', 'to' => array( 'angry@example.com' ) ) ) );

		$this->assertSame( MessageRecord::STATUS_COMPLAINED, $this->messages->find( (int) $message->id )->status, 'complaint is not downgraded to bounce' );
		// Suppression still applies (idempotently).
		$this->assertTrue( $this->suppression->is_suppressed( 'angry@example.com' ) );
	}

	public function test_a_late_positive_event_never_resurrects_a_bounced_message(): void {
		$message = $this->sent_message( 'resend-7', 'dead@example.com' );
		$this->processor->process( $this->event( 'email.bounced', array( 'email_id' => 'resend-7', 'to' => array( 'dead@example.com' ) ) ) );

		// A delivered/opened event arriving after the bounce must not overwrite it.
		$this->processor->process( $this->event( 'email.delivered', array( 'email_id' => 'resend-7' ) ) );
		$this->processor->process( $this->event( 'email.opened', array( 'email_id' => 'resend-7' ) ) );

		$this->assertSame( MessageRecord::STATUS_BOUNCED, $this->messages->find( (int) $message->id )->status );
		$this->assertTrue( $this->suppression->is_suppressed( 'dead@example.com' ) );
	}

	public function test_unknown_event_type_is_ignored(): void {
		$message = $this->sent_message( 'resend-6' );

		$handled = $this->processor->process( $this->event( 'email.delivery_delayed', array( 'email_id' => 'resend-6' ) ) );

		$this->assertFalse( $handled );
		$this->assertSame( MessageRecord::STATUS_SENT, $this->messages->find( (int) $message->id )->status );
	}
}
