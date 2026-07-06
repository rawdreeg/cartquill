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
use CartQuill\Automations\SheetsAction;
use CartQuill\Automations\SheetsResult;
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
use CartQuill\Tests\Fake\StubSheetsClient;
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
	private StubSheetsClient $sheets;
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
		$this->sheets      = new StubSheetsClient();
		$this->enroller    = new Enroller( $this->enrollments, $this->scheduler, $this->clock );
	}

	private function connect_slack(): void {
		$this->connections->save(
			new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'https://hooks.slack.com/services/x' ) )
		);
	}

	private function connect_sheets(): void {
		$this->connections->save(
			new ConnectionRecord(
				null,
				'sheets',
				ConnectionRecord::STATUS_CONNECTED,
				array(
					'service_account' => '{"client_email":"bot@proj.iam.gserviceaccount.com","private_key":"KEY"}',
					'spreadsheet_id'  => 'SHEET-1',
					'range'           => 'Sheet1',
				)
			)
		);
	}

	private function runner(): StepRunner {
		$actions = new ActionRegistry();
		$actions->register( new SlackAction( $this->connections, $this->slack, new Renderer() ) );
		$actions->register( new SheetsAction( $this->connections, $this->sheets, new Renderer() ) );

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

	public function test_first_time_paid_order_fans_out_to_slack_and_sheets(): void {
		$this->connect_slack();
		$this->connect_sheets();
		$this->activity->record_order( 'buyer@example.com', self::T0 ); // first order

		// Suppress the buyer's email to prove the internal actions ignore it.
		$this->suppression->suppress( 'buyer@example.com', 'unsubscribe' );

		// A real Slack incoming webhook acknowledges with "ok" and no message ts,
		// so the recorded external id is null (like wp_mail).
		$this->slack->will_return( SlackResult::ok() );

		$flow = $this->active( AutomationsRecipes::order_alert() );
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_paid', array( 'order_id' => 42, 'order_total' => 50 ) );

		$this->tick( $this->runner() );

		// One trigger fanned across two tools.
		$this->assertSame( 1, $this->slack->count(), 'suppression is not consulted for internal actions' );
		$this->assertSame( 1, $this->sheets->count() );
		$this->assertStringContainsString( 'buyer@example.com', $this->slack->last()['text'] );
		$this->assertSame( array( '42', 'buyer@example.com', '50' ), $this->sheets->last()['row'], 'the sale is logged from context' );

		// Each action is its own (enrollment, step) message with the right channel.
		$byStep = array();
		foreach ( $this->messages->all() as $m ) {
			$byStep[ $m->step_index ] = $m;
		}
		$this->assertSame( SlackAction::TYPE, $byStep[0]->channel );
		$this->assertSame( 'slack', $byStep[0]->sender );
		$this->assertNull( $byStep[0]->external_id, 'an incoming webhook returns no message id' );
		$this->assertSame( MessageRecord::STATUS_SENT, $byStep[0]->status );
		$this->assertSame( SheetsAction::TYPE, $byStep[1]->channel );
		$this->assertSame( 'google_sheets', $byStep[1]->sender );
		$this->assertSame( 'Sheet1!A5:C5', $byStep[1]->external_id, 'the Sheets updated range is recorded' );
		$this->assertSame( MessageRecord::STATUS_SENT, $byStep[1]->status );

		$this->assertSame( 0, $this->sender->count(), 'no email is sent by this recipe' );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	public function test_sheets_failure_dead_letters_without_affecting_the_sent_slack_step(): void {
		$this->connect_slack();
		$this->connect_sheets();
		$this->activity->record_order( 'buyer@example.com', self::T0 );
		$this->slack->will_return( SlackResult::ok() );
		$this->sheets->will_return( SheetsResult::failed( 'Sheets API responded 500.' ) );

		$flow = $this->active( AutomationsRecipes::order_alert() );
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_paid', array( 'order_id' => 1, 'order_total' => 10 ) );

		// Step 0 (Slack) sends; step 1 (Sheets) fails attempt 1 and reschedules.
		$this->tick( $this->runner() );
		$this->assertSame( 1, $this->slack->count() );

		$this->clock->advance( 300 );
		$this->tick( $this->runner() ); // Sheets attempt 2
		$this->clock->advance( 600 );
		$this->tick( $this->runner() ); // Sheets attempt 3 -> dead-letter -> advance -> complete

		$byStep = array();
		foreach ( $this->messages->all() as $m ) {
			$byStep[ $m->step_index ] = $m;
		}
		$this->assertSame( MessageRecord::STATUS_SENT, $byStep[0]->status, 'the already-sent Slack step is untouched' );
		$this->assertSame( 1, $this->slack->count(), 'Slack was not re-posted while Sheets retried' );
		$this->assertSame( MessageRecord::STATUS_FAILED, $byStep[1]->status, 'Sheets dead-lettered after its retries' );
		$this->assertSame( 3, $byStep[1]->attempts );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	public function test_returning_customer_is_skipped(): void {
		$this->connect_slack();
		$this->connect_sheets();
		$this->activity->record_order( 'buyer@example.com', self::T0 - 100 );
		$this->activity->record_order( 'buyer@example.com', self::T0 ); // second order

		$flow = $this->active( AutomationsRecipes::order_alert() );
		$this->enroller->enroll( $flow, 'buyer@example.com', 'order_paid' );

		$this->tick( $this->runner() );

		$this->assertSame( 0, $this->slack->count(), 'the first-time gate skips a returning customer' );
		$this->assertSame( 0, $this->sheets->count(), 'both steps skip' );
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
