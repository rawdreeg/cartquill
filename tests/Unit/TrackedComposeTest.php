<?php
/**
 * The composer embeds tracking, and a wrapped link round-trips through the
 * endpoint to record a click on the right message.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Engine\MessageComposer;
use FlowForge\Flow\FlowStep;
use FlowForge\Flow\Renderer;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Settings\ArraySettings;
use FlowForge\Tracking\SelfHostedLinkTracker;
use FlowForge\Tracking\Signer;
use FlowForge\Tracking\TrackingEndpoint;
use FlowForge\Tracking\TrackingUrls;
use PHPUnit\Framework\TestCase;

final class TrackedComposeTest extends TestCase {

	public function test_composed_email_has_pixel_and_a_wrapped_link_that_records_a_click(): void {
		$signer   = new Signer( 'secret' );
		$urls     = new TrackingUrls( 'https://shop.test/', $signer );
		$messages = new InMemoryMessageRepository();
		$endpoint = new TrackingEndpoint( $messages, $signer, $urls );

		$composer = new MessageComposer(
			new Renderer(),
			new ArraySettings( 'Acme', 'hello@acme.test' ),
			new SelfHostedLinkTracker( $urls )
		);

		// A sent message row (id 1) that the email will reference.
		$record = $messages->claim(
			new MessageRecord( null, 1, 1, 0, 'buyer@example.com', 'wp_mail', MessageRecord::STATUS_QUEUED )
		);
		$id = (int) $record->id;
		$messages->update_status( $id, MessageRecord::STATUS_SENT );

		$step    = new FlowStep( 0, 'Hi', '<p><a href="https://shop.test/deal">Shop the deal</a></p>' );
		$message = $composer->compose( $step, 'buyer@example.com', 1, 0, 1, $id );

		// Pixel embedded, and the content link is wrapped through the redirect.
		$this->assertStringContainsString( 'flowforge_track=open', $message->body );
		$this->assertStringContainsString( 'flowforge_track=click', $message->body );

		// Recover the signed click URL from the body and drive it.
		$this->assertSame( 1, preg_match( '/href="([^"]*flowforge_track=click[^"]*)"/', $message->body, $m ) );
		parse_str( (string) parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $q );

		$redirect = $endpoint->handle_click( (int) $q['mid'], rawurldecode( $q['url'] ), $q['t'] );

		$this->assertSame( 'https://shop.test/deal', $redirect );
		$this->assertSame( MessageRecord::STATUS_CLICKED, $messages->find( $id )->status );
	}
}
