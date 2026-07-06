<?php
/**
 * The step runner driving typed actions: channel recording, unavailable-action
 * dead-lettering, channel-aware suppression, and cross-channel idempotency.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionRegistry;
use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Flow\FlowStep;
use CartQuill\Flow\Renderer;
use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Persistence\MessageRecord;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Sender\FakeSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use CartQuill\Tests\Fake\RecordingAction;
use PHPUnit\Framework\TestCase;

final class ActionEngineTest extends TestCase {

	private const T0 = 1_700_000_000;

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryMessageRepository $messages;
	private FakeSender $sender;
	private ArraySuppressionList $suppression;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private Enroller $enroller;

	protected function setUp(): void {
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->messages    = new InMemoryMessageRepository();
		$this->sender      = new FakeSender();
		$this->suppression = new ArraySuppressionList();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( self::T0 );
		$this->enroller    = new Enroller( $this->enrollments, $this->scheduler, $this->clock );
	}

	private function runner( ?ActionRegistry $actions = null ): StepRunner {
		return new StepRunner(
			$this->flows,
			$this->enrollments,
			$this->messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$this->sender,
			$this->suppression,
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$this->scheduler,
			$this->clock,
			$actions,
		);
	}

	/**
	 * @param list<FlowStep> $steps
	 */
	private function active_flow( array $steps ): FlowRecord {
		return $this->flows->save(
			new FlowRecord( null, 'Test', 'recipe', FlowRecord::STATUS_ACTIVE, FlowRecord::SOURCE_TEMPLATE, $steps )
		);
	}

	private function tick( StepRunner $runner ): void {
		$this->scheduler->run_due( $this->clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );
	}

	public function test_email_action_routes_through_sender_and_records_channel_email(): void {
		$runner = $this->runner();
		$flow   = $this->active_flow( array( new FlowStep( 0, 'Hi', 'body' ) ) );
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$this->tick( $runner );

		$this->assertSame( 1, $this->sender->count(), 'the email still routes through SenderInterface' );
		$message = $this->messages->all()[0];
		$this->assertSame( MessageRecord::CHANNEL_EMAIL, $message->channel );
		$this->assertSame( MessageRecord::STATUS_SENT, $message->status );
		$this->assertSame( 'buyer@example.com', $message->target, 'email records the customer as its target' );
	}

	public function test_unavailable_action_dead_letters_and_advances(): void {
		// Registry has only the core email action; the slack step is unavailable.
		$runner = $this->runner( new ActionRegistry() );
		$flow   = $this->active_flow(
			array(
				new FlowStep( 0, '', '', array(), 'slack_post' ),
				new FlowStep( 0, 'Hi', 'body' ),
			)
		);
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$this->tick( $runner ); // step 0 unavailable -> dead-letter -> advance -> step 1 sends

		$byStep = array();
		foreach ( $this->messages->all() as $m ) {
			$byStep[ $m->step_index ] = $m;
		}
		$this->assertSame( MessageRecord::STATUS_FAILED, $byStep[0]->status, 'unavailable action is dead-lettered' );
		$this->assertSame( 'slack_post', $byStep[0]->channel );
		$this->assertSame( MessageRecord::STATUS_SENT, $byStep[1]->status, 'the flow advanced past it' );
		$this->assertSame( 1, $this->sender->count() );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status, 'flow never stalls' );
	}

	public function test_internal_action_skips_suppression(): void {
		$slack   = new RecordingAction( 'slack_post', customer_facing: false, target: null );
		$actions = new ActionRegistry();
		$actions->register( $slack );
		$runner = $this->runner( $actions );

		// Even though this customer's *email* is suppressed, an internal action is
		// not a customer touch and must still run.
		$this->suppression->suppress( 'buyer@example.com', 'unsubscribe' );

		$flow = $this->active_flow( array( new FlowStep( 0, '', '', array(), 'slack_post' ) ) );
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$this->tick( $runner );

		$this->assertSame( 1, $slack->count(), 'internal action ran despite email suppression' );
		$this->assertSame( 0, $this->sender->count(), 'nothing went through the email sender' );
		$message = $this->messages->all()[0];
		$this->assertSame( 'slack_post', $message->channel );
		$this->assertSame( MessageRecord::STATUS_SENT, $message->status );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	public function test_customer_facing_action_is_suppressed_on_its_own_channel(): void {
		$phone   = '+15551230000';
		$sms     = new RecordingAction( 'sms_send', customer_facing: true, target: $phone, sender_key: 'twilio' );
		$actions = new ActionRegistry();
		$actions->register( $sms );
		$runner = $this->runner( $actions );

		// A number that texted STOP is suppressed on the sms_send channel.
		$this->suppression->suppress( $phone, 'sms_stop', 'sms_send' );

		$flow = $this->active_flow( array( new FlowStep( 0, '', '', array(), 'sms_send' ) ) );
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$this->tick( $runner );

		$this->assertSame( 0, $sms->count(), 'a suppressed number is never texted' );
		$this->assertSame( EnrollmentRecord::STATUS_UNSUBSCRIBED, $this->enrollments->all()[0]->status );
	}

	public function test_customer_facing_action_sends_when_target_not_suppressed(): void {
		$phone   = '+15559990000';
		$sms     = new RecordingAction( 'sms_send', customer_facing: true, target: $phone, sender_key: 'twilio' );
		$actions = new ActionRegistry();
		$actions->register( $sms );
		$runner = $this->runner( $actions );

		// A *different* number is suppressed; ours is clear.
		$this->suppression->suppress( '+15551230000', 'sms_stop', 'sms_send' );

		$flow = $this->active_flow( array( new FlowStep( 0, '', '', array(), 'sms_send' ) ) );
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		$this->tick( $runner );

		$this->assertSame( 1, $sms->count() );
		$message = $this->messages->all()[0];
		$this->assertSame( 'sms_send', $message->channel );
		$this->assertSame( 'twilio', $message->sender );
		$this->assertSame( $phone, $message->target );
		$this->assertSame( MessageRecord::STATUS_SENT, $message->status );
	}

	public function test_action_step_is_never_executed_twice(): void {
		$slack   = new RecordingAction( 'slack_post', customer_facing: false, target: null );
		$actions = new ActionRegistry();
		$actions->register( $slack );
		$runner = $this->runner( $actions );

		$flow       = $this->active_flow( array( new FlowStep( 0, '', '', array(), 'slack_post' ) ) );
		$enrollment = $this->enroller->enroll( $flow, 'buyer@example.com' );
		$id         = (int) $enrollment->id;

		$runner->run_step( $id, 0 );
		$runner->run_step( $id, 0 ); // forced duplicate

		$this->assertSame( 1, $slack->count(), 'idempotency holds for non-email channels too' );
		$this->assertCount( 1, $this->messages->all() );
	}
}
