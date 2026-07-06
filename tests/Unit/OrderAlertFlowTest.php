<?php
/**
 * The New-order Slack alert recipe end-to-end: first-time gate, channel
 * recording, suppression exemption, and dead-letter-on-failure.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionRegistry;
use CartQuill\Automations\AutomationsRecipes;
use CartQuill\Automations\SlackAction;
use CartQuill\Automations\SlackResult;
use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Flow\FlowStep;
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
use CartQuill\Tests\Fake\StubSlackClient;
use PHPUnit\Framework\TestCase;

final class OrderAlertFlowTest extends TestCase {

	private const T0 = 1_700_000_000;

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryMessageRepository $messages;
	private FakeSender $sender;
	private ArraySuppressionList $suppression;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private FakeCustomerActivity $activity;
	private InMemoryConnectionStore $connections;
	private StubSlackClient $slack;
	private Enroller $enroller;

	protected function setUp(): void {
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->messages    = new InMemoryMessageRepository();
		$this->sender      = new FakeSender();
		$this->suppression = new ArraySuppressionList();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( self::T0 );
		$this->activity    = new FakeCustomerActivity();
		$this->connections = new InMemoryConnectionStore();
		$this->slack       = new StubSlackClient();
		$this->enroller    = new Enroller( $this->enrollments, $this->scheduler, $this->clock );
	}

	private function connect_slack(): void {
		$this->connections->save(
			new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'https://hooks.slack.com/services/x' ) )
		);
	}

	private function runner(): StepRunner {
		$actions = new ActionRegistry();
		$actions->register( new SlackAction( $this->connections, $this->slack, new Renderer() ) );

		return new StepRunner(
			$this->flows,
			$this->enrollments,
			$this->messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$this->sender,
			$this->suppression,
			new ConditionEvaluator( $this->activity ),
			$this->scheduler,
			$this->clock,
			$actions,
		);
	}

	private function active( FlowRecord $flow ): FlowRecord {
		return $this->flows->save(
			new FlowRecord( null, $flow->name, $flow->type, FlowRecord::STATUS_ACTIVE, $flow->source, $flow->steps )
		);
	}

	private function tick( StepRunner $runner ): void {
		$this->scheduler->run_due( $this->clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );
	}

	public function test_first_time_paid_order_posts_to_slack_and_records_the_channel(): void {
		$this->connect_slack();
		$this->activity->record_order( 'buyer@example.com', self::T0 ); // first order

		// Suppress the buyer's email to prove the internal action ignores it.
		$this->suppression->suppress( 'buyer@example.com', 'unsubscribe' );

		// A real Slack incoming webhook acknowledges with "ok" and no message ts,
		// so the recorded external id is null (like wp_mail). The channel is what
		// distinguishes the touch.
		$this->slack->will_return( SlackResult::ok() );

		$flow = $this->active( AutomationsRecipes::order_alert() );
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_paid', array( 'order_total' => 50 ) );

		$this->tick( $this->runner() );

		$this->assertSame( 1, $this->slack->count(), 'suppression is not consulted for an internal action' );
		$this->assertStringContainsString( 'buyer@example.com', $this->slack->last()['text'], 'the alert names the customer' );

		$message = $this->messages->all()[0];
		$this->assertSame( SlackAction::TYPE, $message->channel );
		$this->assertSame( 'slack', $message->sender );
		$this->assertNull( $message->external_id, 'an incoming webhook returns no message id' );
		$this->assertSame( MessageRecord::STATUS_SENT, $message->status );
		$this->assertSame( 0, $this->sender->count(), 'no email is sent by this recipe' );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	public function test_returning_customer_is_skipped(): void {
		$this->connect_slack();
		$this->activity->record_order( 'buyer@example.com', self::T0 - 100 );
		$this->activity->record_order( 'buyer@example.com', self::T0 ); // second order

		$flow = $this->active( AutomationsRecipes::order_alert() );
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_paid' );

		$this->tick( $this->runner() );

		$this->assertSame( 0, $this->slack->count(), 'the first-time gate skips a returning customer' );
		$this->assertCount( 0, $this->messages->all(), 'a skipped step records no message' );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	public function test_failed_slack_post_dead_letters_and_the_flow_advances(): void {
		$this->connect_slack();
		$this->activity->record_order( 'buyer@example.com', self::T0 );
		$this->slack->will_return( SlackResult::failed( 'Slack responded 500' ) );

		// A two-step flow: the Slack post fails, an email step follows.
		$flow = $this->active(
			new FlowRecord(
				null,
				'Alert then email',
				'order_alert',
				FlowRecord::STATUS_ACTIVE,
				FlowRecord::SOURCE_TEMPLATE,
				array(
					new FlowStep( 0, '', '', array(), SlackAction::TYPE, array( 'channel' => '#orders' ) ),
					new FlowStep( 0, 'Thanks', 'Your order is confirmed.' ),
				)
			)
		);
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_paid' );

		// Attempt 1 fails and reschedules with backoff; drive the retries.
		$this->tick( $this->runner() );
		$this->clock->advance( 300 );
		$this->tick( $this->runner() );
		$this->clock->advance( 600 );
		$this->tick( $this->runner() ); // 3rd attempt -> dead-letter -> advance to the email step

		$byStep = array();
		foreach ( $this->messages->all() as $m ) {
			$byStep[ $m->step_index ] = $m;
		}
		$this->assertSame( MessageRecord::STATUS_FAILED, $byStep[0]->status, 'the Slack step is dead-lettered' );
		$this->assertSame( SlackAction::TYPE, $byStep[0]->channel );
		$this->assertSame( MessageRecord::STATUS_SENT, $byStep[1]->status, 'the flow advanced to the email step' );
		$this->assertSame( 1, $this->sender->count() );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}
}
