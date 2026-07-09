<?php
/**
 * MessageComposer guarantees an unsubscribe on every email and renders context.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Engine\MessageComposer;
use CartQuill\Flow\FlowStep;
use CartQuill\Flow\Renderer;
use CartQuill\Settings\ArraySettings;
use PHPUnit\Framework\TestCase;

final class MessageComposerTest extends TestCase {

	private function composer( string $from_email = 'hello@acme.test' ): MessageComposer {
		return new MessageComposer( new Renderer(), new ArraySettings( 'Acme', $from_email ) );
	}

	public function test_renders_context_and_carries_unsubscribe(): void {
		$step    = new FlowStep( 0, 'Hi {{ customer_email }}', '<p>{{ store_name }}</p>' );
		$message = $this->composer()->compose( $step, 'buyer@example.com', 3, 0, 9 );

		$this->assertSame( 'Hi buyer@example.com', $message->subject );
		$this->assertStringContainsString( '<p>Acme</p>', $message->body );
		$this->assertStringContainsString( 'Unsubscribe', $message->body );
		$this->assertSame( 'mailto:hello@acme.test?subject=unsubscribe', $message->unsubscribe );
		$this->assertContains(
			'List-Unsubscribe: <mailto:hello@acme.test?subject=unsubscribe>',
			$message->header_lines()
		);
		$this->assertSame( 3, $message->flow_id );
		$this->assertSame( 9, $message->enrollment_id );
	}

	public function test_footer_present_even_without_a_from_address(): void {
		$step    = new FlowStep( 0, 'Hi', '<p>body</p>' );
		$message = $this->composer( '' )->compose( $step, 'buyer@example.com', 3, 0, 9 );

		// Compliance rule holds structurally: every email carries an unsubscribe.
		$this->assertStringContainsString( 'unsubscribe', strtolower( $message->body ) );
		$this->assertNull( $message->unsubscribe, 'no List-Unsubscribe header without a target' );
	}

	public function test_body_is_wrapped_in_the_branded_email_shell(): void {
		$step    = new FlowStep( 0, 'Hi', '<p>{{ store_name }} says hi</p>' );
		$message = $this->composer()->compose( $step, 'buyer@example.com', 3, 0, 9 );

		// The rendered content still lands inside the message…
		$this->assertStringContainsString( '<p>Acme says hi</p>', $message->body );
		// …now inside a self-contained branded HTML document with the store name.
		$this->assertStringContainsString( '<!DOCTYPE html', $message->body );
		$this->assertStringContainsString( 'Acme', $message->body );
		// …and the unsubscribe compliance footer survives the wrapping.
		$this->assertStringContainsString( 'Unsubscribe', $message->body );
	}
}
