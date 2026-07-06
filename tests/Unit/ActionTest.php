<?php
/**
 * The Action layer: EmailAction wraps the send seam, ActionRegistry resolves by
 * type, ActionContext carries step/config/context.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionContext;
use CartQuill\Action\ActionRegistry;
use CartQuill\Action\EmailAction;
use CartQuill\Engine\MessageComposer;
use CartQuill\Flow\FlowStep;
use CartQuill\Flow\Renderer;
use CartQuill\Sender\FakeSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Tests\Fake\RecordingAction;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase {

	private function email_action( FakeSender $sender ): EmailAction {
		return new EmailAction(
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$sender,
		);
	}

	private function context( FlowStep $step ): ActionContext {
		return new ActionContext(
			step: $step,
			customer_email: 'buyer@example.com',
			flow_id: 1,
			step_index: 0,
			enrollment_id: 7,
		);
	}

	public function test_email_action_identity(): void {
		$action = $this->email_action( new FakeSender() );

		$this->assertSame( 'email', $action->type() );
		$this->assertSame( 'fake', $action->sender_key(), 'records the wrapped sender key' );
		$this->assertTrue( $action->is_customer_facing() );
		$this->assertSame(
			'buyer@example.com',
			$action->target( $this->context( new FlowStep( 0, 'Hi', 'body' ) ) ),
			'email targets the customer address'
		);
	}

	public function test_email_action_routes_through_the_sender_and_renders_the_step(): void {
		$sender = new FakeSender();
		$action = $this->email_action( $sender );

		$result = $action->execute( $this->context( new FlowStep( 0, 'Welcome {{store_name}}', '<p>Hello</p>' ) ) );

		$this->assertTrue( $result->is_accepted() );
		$this->assertSame( 1, $sender->count(), 'the send went through SenderInterface' );
		$message = $sender->last();
		$this->assertSame( 'buyer@example.com', $message->to );
		$this->assertSame( 'Welcome Acme', $message->subject, 'subject template rendered' );
		$this->assertStringContainsString( 'Hello', $message->body );
		$this->assertStringContainsString( 'Unsubscribe', $message->body, 'compliance footer preserved' );
	}

	public function test_registry_resolves_and_reports_types(): void {
		$registry = new ActionRegistry();
		$email    = $this->email_action( new FakeSender() );
		$slack    = new RecordingAction( 'slack_post' );

		$registry->register( $email );
		$registry->register( $slack );

		$this->assertSame( $email, $registry->get( 'email' ) );
		$this->assertSame( $slack, $registry->get( 'slack_post' ) );
		$this->assertNull( $registry->get( 'missing' ), 'unknown type resolves to null, not an error' );
		$this->assertTrue( $registry->has( 'slack_post' ) );
		$this->assertFalse( $registry->has( 'missing' ) );
		$this->assertEqualsCanonicalizing( array( 'email', 'slack_post' ), $registry->types() );
	}

	public function test_context_exposes_config_and_trigger_values(): void {
		$step    = new FlowStep( 0, '', '', array(), 'slack_post', array( 'channel' => '#orders' ) );
		$context = new ActionContext(
			step: $step,
			customer_email: 'buyer@example.com',
			flow_id: 1,
			step_index: 2,
			enrollment_id: 7,
			context: array( 'cart_value' => 75 ),
		);

		$this->assertSame( '#orders', $context->config( 'channel' ) );
		$this->assertSame( 'fallback', $context->config( 'missing', 'fallback' ) );
		$this->assertSame( 75, $context->value( 'cart_value' ) );
		$this->assertNull( $context->value( 'phone' ) );

		$stamped = $context->with_message_id( 42 );
		$this->assertSame( 42, $stamped->message_id );
		$this->assertSame( 0, $context->message_id, 'with_message_id is immutable' );
	}
}
