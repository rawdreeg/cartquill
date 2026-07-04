<?php
/**
 * MessageComposer guarantees an unsubscribe on every email and renders context.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Engine\MessageComposer;
use FlowForge\Flow\FlowStep;
use FlowForge\Flow\Renderer;
use FlowForge\Settings\ArraySettings;
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
}
