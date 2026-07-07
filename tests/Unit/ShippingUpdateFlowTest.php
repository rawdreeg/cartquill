<?php
/**
 * Recipe 3 (Shipping update) end-to-end: text on ship when a phone is on file,
 * skip when not, and never text a STOP-ed number.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionRegistry;
use CartQuill\Automations\AutomationsRecipes;
use CartQuill\Automations\SmsAction;
use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Flow\Renderer;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Persistence\MessageRecord;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Sender\FakeSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use CartQuill\Tests\Fake\StubTwilioClient;
use PHPUnit\Framework\TestCase;

final class ShippingUpdateFlowTest extends TestCase {

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryMessageRepository $messages;
	private ArraySuppressionList $suppression;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private InMemoryConnectionStore $connections;
	private StubTwilioClient $twilio;
	private Enroller $enroller;

	protected function setUp(): void {
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->messages    = new InMemoryMessageRepository();
		$this->suppression = new ArraySuppressionList();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( 1_700_000_000 );
		$this->connections = new InMemoryConnectionStore();
		$this->twilio      = new StubTwilioClient();
		$this->enroller    = new Enroller( $this->enrollments, $this->scheduler, $this->clock );

		$this->connections->save(
			new ConnectionRecord( null, 'twilio', ConnectionRecord::STATUS_CONNECTED, array( 'account_sid' => 'AC1', 'auth_token' => 'tok', 'from_number' => '+15550000000' ) )
		);
	}

	private function tick(): void {
		$actions = new ActionRegistry();
		$actions->register( new SmsAction( $this->connections, $this->twilio, new Renderer() ) );
		$runner = new StepRunner(
			$this->flows,
			$this->enrollments,
			$this->messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			new FakeSender(),
			$this->suppression,
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$this->scheduler,
			$this->clock,
			$actions,
		);
		$this->scheduler->run_due( $this->clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );
	}

	private function active(): FlowRecord {
		$r = AutomationsRecipes::shipping_update();
		return $this->flows->save( new FlowRecord( null, $r->name, $r->type, FlowRecord::STATUS_ACTIVE, $r->source, $r->steps ) );
	}

	public function test_shipped_order_with_a_phone_sends_one_sms(): void {
		$flow = $this->active();
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_shipped', array( 'phone' => '+15551112222', 'tracking_url' => 'https://track/1' ) );

		$this->tick();

		$this->assertSame( 1, $this->twilio->count() );
		$this->assertStringContainsString( 'https://track/1', $this->twilio->last()['body'] );
		$message = $this->messages->all()[0];
		$this->assertSame( SmsAction::TYPE, $message->channel );
		$this->assertSame( 'twilio', $message->sender );
		$this->assertSame( 'SM1234567890', $message->external_id, 'the Twilio SID is recorded' );
		$this->assertSame( '+15551112222', $message->target );
		$this->assertSame( MessageRecord::STATUS_SENT, $message->status );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	public function test_order_without_a_phone_skips(): void {
		$flow = $this->active();
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_shipped', array( 'phone' => '' ) );

		$this->tick();

		$this->assertSame( 0, $this->twilio->count(), 'the has_phone gate skips' );
		$this->assertCount( 0, $this->messages->all() );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	public function test_a_stopped_number_is_never_texted(): void {
		$this->suppression->suppress( '+15551112222', 'sms_stop', SmsAction::TYPE );
		$flow = $this->active();
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_shipped', array( 'phone' => '+15551112222' ) );

		$this->tick();

		$this->assertSame( 0, $this->twilio->count(), 'a STOP-ed number is never texted (suppression-first)' );
		$this->assertSame( EnrollmentRecord::STATUS_UNSUBSCRIBED, $this->enrollments->all()[0]->status );
	}
}
