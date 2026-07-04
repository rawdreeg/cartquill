<?php
/**
 * The tracer-bullet test: a trigger produces exactly one sent email and one
 * recorded `messages` row, observed entirely through the FakeSender seam.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Engine\SpineDispatcher;
use FlowForge\Flow\FlowDefinition;
use FlowForge\Flow\FlowStep;
use FlowForge\Flow\Renderer;
use FlowForge\Model\SendResult;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Sender\FakeSender;
use FlowForge\Settings\ArraySettings;
use FlowForge\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class SpineDispatcherTest extends TestCase {

	private FakeSender $sender;
	private InMemoryMessageRepository $messages;
	private SpineDispatcher $dispatcher;

	protected function setUp(): void {
		$this->sender     = new FakeSender();
		$this->messages   = new InMemoryMessageRepository();
		$this->dispatcher = new SpineDispatcher(
			$this->sender,
			$this->messages,
			new Renderer(),
			new ArraySettings( 'Acme Store', 'hello@acme.test' ),
			new FixedClock( 1_700_000_000 ),
		);
	}

	private function spine_flow(): FlowDefinition {
		return new FlowDefinition(
			id: 1,
			name: 'Spine',
			type: 'spine',
			steps: array(
				new FlowStep(
					delay: 0,
					subject: 'Thanks {{ customer_email }}',
					body: '<p>Welcome to {{ store_name }}.</p>',
				),
			),
		);
	}

	public function test_trigger_sends_exactly_one_email(): void {
		$this->dispatcher->dispatch( $this->spine_flow(), 0, 'buyer@example.com' );

		$this->assertSame( 1, $this->sender->count() );
		$this->assertSame( 'buyer@example.com', $this->sender->last()->to );
	}

	public function test_step_templates_are_rendered_with_context(): void {
		$this->dispatcher->dispatch( $this->spine_flow(), 0, 'buyer@example.com' );

		$message = $this->sender->last();
		$this->assertSame( 'Thanks buyer@example.com', $message->subject );
		$this->assertStringContainsString( '<p>Welcome to Acme Store.</p>', $message->body );
		$this->assertSame( 'Acme Store', $message->from_name );
		$this->assertSame( 'hello@acme.test', $message->from_email );
	}

	public function test_every_email_carries_an_unsubscribe_link_and_header(): void {
		$this->dispatcher->dispatch( $this->spine_flow(), 0, 'buyer@example.com' );

		$message  = $this->sender->last();
		$expected = 'mailto:hello@acme.test?subject=unsubscribe';

		// Locked compliance rule: unsubscribe link on every email.
		$this->assertStringContainsString( 'Unsubscribe', $message->body );
		$this->assertStringContainsString( $expected, $message->body );
		$this->assertSame( $expected, $message->unsubscribe );
		$this->assertContains(
			'List-Unsubscribe: <' . $expected . '>',
			$message->header_lines()
		);
	}

	public function test_successful_send_records_a_sent_message_row(): void {
		$this->dispatcher->dispatch( $this->spine_flow(), 0, 'buyer@example.com', 42 );

		$rows = $this->messages->all();
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertNotNull( $row->id );
		$this->assertSame( MessageRecord::STATUS_SENT, $row->status );
		$this->assertSame( 'fake', $row->sender );
		$this->assertSame( 'buyer@example.com', $row->recipient );
		$this->assertSame( 42, $row->enrollment_id );
		$this->assertSame( 1, $row->flow_id );
		$this->assertSame( 0, $row->step_index );
		$this->assertNotNull( $row->external_id );
		$this->assertSame( '2023-11-14 22:13:20', $row->sent_at );
	}

	public function test_failed_send_records_a_failed_message_row(): void {
		$this->sender->will_return( SendResult::failed( 'transport down' ) );

		$this->dispatcher->dispatch( $this->spine_flow(), 0, 'buyer@example.com' );

		$row = $this->messages->all()[0];
		$this->assertSame( MessageRecord::STATUS_FAILED, $row->status );
	}

	public function test_dispatching_an_unknown_step_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->dispatcher->dispatch( $this->spine_flow(), 5, 'buyer@example.com' );
	}
}
