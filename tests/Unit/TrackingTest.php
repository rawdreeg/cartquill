<?php
/**
 * Self-hosted open/click tracking: signing, link wrapping, and the endpoint's
 * forward-only status transitions with open-redirect protection.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Tracking\SelfHostedLinkTracker;
use FlowForge\Tracking\Signer;
use FlowForge\Tracking\TrackingEndpoint;
use FlowForge\Tracking\TrackingUrls;
use PHPUnit\Framework\TestCase;

final class TrackingTest extends TestCase {

	private Signer $signer;
	private TrackingUrls $urls;
	private InMemoryMessageRepository $messages;
	private TrackingEndpoint $endpoint;

	protected function setUp(): void {
		$this->signer   = new Signer( 'test-secret' );
		$this->urls     = new TrackingUrls( 'https://shop.test/', $this->signer );
		$this->messages = new InMemoryMessageRepository();
		$this->endpoint = new TrackingEndpoint( $this->messages, $this->signer, $this->urls );
	}

	private function sent_message(): int {
		$record = $this->messages->claim(
			new MessageRecord( null, 1, 1, 0, 'buyer@example.com', 'wp_mail', MessageRecord::STATUS_QUEUED )
		);
		$this->messages->update_status( (int) $record->id, MessageRecord::STATUS_SENT );
		return (int) $record->id;
	}

	public function test_signer_verifies_only_untampered_payloads(): void {
		$sig = $this->signer->sign( 'open:5' );
		$this->assertTrue( $this->signer->verify( 'open:5', $sig ) );
		$this->assertFalse( $this->signer->verify( 'open:6', $sig ) );
		$this->assertFalse( $this->signer->verify( 'open:5', 'deadbeef' ) );
	}

	public function test_wrap_links_rewrites_http_links_only(): void {
		$tracker = new SelfHostedLinkTracker( $this->urls );
		$body    = '<a href="https://shop.test/p/1">Buy</a> '
			. '<a href="mailto:hi@shop.test">Email</a> '
			. '<a href="#top">Top</a>';

		$wrapped = $tracker->wrap_links( $body, 7 );

		$this->assertStringContainsString( 'flowforge_track=click', $wrapped );
		$this->assertStringContainsString( 'mid=7', $wrapped );
		$this->assertStringContainsString( 'mailto:hi@shop.test', $wrapped ); // untouched
		$this->assertStringContainsString( 'href="#top"', $wrapped );          // untouched
	}

	public function test_pixel_html_targets_the_message(): void {
		$tracker = new SelfHostedLinkTracker( $this->urls );
		$pixel   = $tracker->pixel_html( 9 );

		$this->assertStringContainsString( 'flowforge_track=open', $pixel );
		$this->assertStringContainsString( 'mid=9', $pixel );
		$this->assertStringContainsString( 'width="1"', $pixel );
	}

	public function test_open_marks_message_opened(): void {
		$id    = $this->sent_message();
		$token = $this->signer->sign( $this->urls->open_payload( $id ) );

		$this->assertTrue( $this->endpoint->handle_open( $id, $token ) );
		$this->assertSame( MessageRecord::STATUS_OPENED, $this->messages->find( $id )->status );
	}

	public function test_forged_open_token_is_rejected(): void {
		$id = $this->sent_message();

		$this->assertFalse( $this->endpoint->handle_open( $id, 'forged' ) );
		$this->assertSame( MessageRecord::STATUS_SENT, $this->messages->find( $id )->status );
	}

	public function test_click_marks_clicked_and_returns_target(): void {
		$id     = $this->sent_message();
		$target = 'https://shop.test/product/1';
		$token  = $this->signer->sign( $this->urls->click_payload( $id, $target ) );

		$redirect = $this->endpoint->handle_click( $id, $target, $token );

		$this->assertSame( $target, $redirect );
		$this->assertSame( MessageRecord::STATUS_CLICKED, $this->messages->find( $id )->status );
	}

	public function test_click_with_tampered_url_is_rejected_no_redirect(): void {
		$id     = $this->sent_message();
		$signed = 'https://shop.test/product/1';
		$token  = $this->signer->sign( $this->urls->click_payload( $id, $signed ) );

		// Attacker swaps the destination but keeps the token for the original URL.
		$redirect = $this->endpoint->handle_click( $id, 'https://evil.test/phish', $token );

		$this->assertNull( $redirect, 'no open redirect: unsigned target is refused' );
		$this->assertSame( MessageRecord::STATUS_SENT, $this->messages->find( $id )->status );
	}

	public function test_click_after_open_still_advances_to_clicked(): void {
		$id     = $this->sent_message();
		$this->messages->update_status( $id, MessageRecord::STATUS_OPENED );
		$target = 'https://shop.test/x';
		$token  = $this->signer->sign( $this->urls->click_payload( $id, $target ) );

		$this->endpoint->handle_click( $id, $target, $token );

		$this->assertSame( MessageRecord::STATUS_CLICKED, $this->messages->find( $id )->status );
	}
}
