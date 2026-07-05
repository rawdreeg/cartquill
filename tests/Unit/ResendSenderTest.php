<?php
/**
 * ResendSender behaviour and its integration behind the engine's sending seam.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Deliverability\ResendSender;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Flow\FlowStep;
use CartQuill\Flow\Renderer;
use CartQuill\Model\Message;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Persistence\MessageRecord;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Sender\SenderRegistry;
use CartQuill\Sender\WpMailSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use CartQuill\Tests\Fake\StubResendClient;
use PHPUnit\Framework\TestCase;

final class ResendSenderTest extends TestCase {

	public function test_key_is_resend(): void {
		$this->assertSame( 'resend', ( new ResendSender( new StubResendClient() ) )->key() );
	}

	public function test_accepted_send_carries_the_resend_id(): void {
		$client = new StubResendClient();
		$sender = new ResendSender( $client );

		$result = $sender->send( new Message( 'buyer@example.com', 'Hi', '<p>Hello</p>', 'Acme', 'hello@acme.test' ) );

		$this->assertTrue( $result->is_accepted() );
		$this->assertSame( 'resend-1', $result->external_id );
		$this->assertCount( 1, $client->sent );
		$this->assertSame( 'buyer@example.com', $client->sent[0]->to );
	}

	public function test_api_failure_becomes_a_failed_result_without_throwing(): void {
		$client = new StubResendClient();
		$client->fail();
		$sender = new ResendSender( $client );

		$result = $sender->send( new Message( 'buyer@example.com', 'Hi', '<p>Hello</p>' ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 'stub resend failure', $result->error );
	}

	public function test_engine_routes_sends_through_resend_and_records_the_id(): void {
		$flows       = new InMemoryFlowRepository();
		$enrollments = new InMemoryEnrollmentRepository();
		$messages    = new InMemoryMessageRepository();
		$scheduler   = new ArrayScheduler();
		$clock       = new FixedClock( 1_700_000_000 );
		$client      = new StubResendClient();

		$runner = new StepRunner(
			$flows,
			$enrollments,
			$messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			new ResendSender( $client ),
			new ArraySuppressionList(),
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$scheduler,
			$clock,
		);

		$flow = $flows->save(
			new FlowRecord( null, 'Welcome', 'welcome', FlowRecord::STATUS_ACTIVE, FlowRecord::SOURCE_TEMPLATE, array( new FlowStep( 0, 'Hi', 'body' ) ) )
		);
		( new Enroller( $enrollments, $scheduler, $clock ) )->enroll( $flow, 'buyer@example.com' );

		$scheduler->run_due( $clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );

		$this->assertCount( 1, $client->sent, 'the engine sent through Resend' );
		$message = $messages->all()[0];
		$this->assertSame( 'resend', $message->sender, 'message records the resend sender key' );
		$this->assertSame( MessageRecord::STATUS_SENT, $message->status );
		$this->assertSame( 'resend-1', $message->external_id, 'the Resend id is stored for later webhook correlation' );
	}

	public function test_switching_sender_leaves_flows_intact(): void {
		$registry = new SenderRegistry( 'wp_mail' );
		$registry->register( new WpMailSender() );
		$registry->register( new ResendSender( new StubResendClient() ) );

		$registry->set_active( 'resend' );
		$this->assertInstanceOf( ResendSender::class, $registry->active() );

		$registry->set_active( 'wp_mail' );
		$this->assertInstanceOf( WpMailSender::class, $registry->active(), 'switching back to wp_mail needs no flow changes' );
	}
}
