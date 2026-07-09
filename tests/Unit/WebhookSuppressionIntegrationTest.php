<?php
/**
 * End-to-end: a bounce webhook suppresses the recipient, and the engine's next
 * scheduled send to that address is then skipped. This proves the channel
 * alignment (EmailAction::TYPE == MessageRecord::CHANNEL_EMAIL ==
 * SuppressionList::CHANNEL_EMAIL) that bounce/complaint auto-suppression relies
 * on — a rename of any of them breaks this test instead of silently shipping.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Deliverability\WebhookProcessor;
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
use PHPUnit\Framework\TestCase;

final class WebhookSuppressionIntegrationTest extends TestCase {

	private const T0 = 1_700_000_000;

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryMessageRepository $messages;
	private ArraySuppressionList $suppression;
	private FakeSender $sender;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private Enroller $enroller;
	private StepRunner $runner;
	private WebhookProcessor $webhooks;

	protected function setUp(): void {
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->messages    = new InMemoryMessageRepository();
		$this->suppression = new ArraySuppressionList();
		$this->sender      = new FakeSender();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( self::T0 );

		$this->enroller = new Enroller( $this->enrollments, $this->scheduler, $this->clock );
		$this->runner   = new StepRunner(
			$this->flows,
			$this->enrollments,
			$this->messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$this->sender,
			$this->suppression,
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$this->scheduler,
			$this->clock,
		);
		// The webhook processor shares the SAME message repo + suppression list the
		// engine reads — exactly the wiring the add-on assembles in production.
		$this->webhooks = new WebhookProcessor( $this->messages, $this->suppression );
	}

	private function tick(): void {
		$this->scheduler->run_due(
			$this->clock->now(),
			fn( int $e, int $s ) => $this->runner->run_step( $e, $s )
		);
	}

	public function test_a_bounce_webhook_suppresses_and_skips_the_next_scheduled_send(): void {
		$flow = $this->flows->save(
			new FlowRecord(
				id: null,
				name: 'Win-back',
				type: 'post_purchase',
				status: FlowRecord::STATUS_ACTIVE,
				source: FlowRecord::SOURCE_TEMPLATE,
				steps: array(
					new FlowStep( 0, 'Reminder 1', 'come back' ),
					new FlowStep( 3600, 'Reminder 2', 'still here' ),
				),
			)
		);
		$this->enroller->enroll( $flow, 'buyer@example.com' );

		// Step 1 fires now; its message records the Resend id for webhook correlation.
		$this->tick();
		$this->assertSame( 1, $this->sender->count() );
		$message = $this->messages->all()[0];
		$this->assertSame( MessageRecord::STATUS_SENT, $message->status );
		$external_id = $message->external_id;
		$this->assertNotSame( '', $external_id );

		// A bounce arrives for that send.
		$handled = $this->webhooks->process(
			array(
				'type' => 'email.bounced',
				'data' => array( 'email_id' => $external_id, 'to' => array( 'buyer@example.com' ) ),
			)
		);
		$this->assertTrue( $handled );
		$this->assertSame( MessageRecord::STATUS_BOUNCED, $this->messages->find( (int) $message->id )->status );
		$this->assertTrue(
			$this->suppression->is_suppressed( 'buyer@example.com' ),
			'the bounced address lands on the global suppression list'
		);

		// The second reminder comes due; suppression must stop it.
		$this->clock->advance( 3600 );
		$this->tick();

		$this->assertSame( 1, $this->sender->count(), 'the next send to a bounced address is skipped' );
		$this->assertSame(
			EnrollmentRecord::STATUS_UNSUBSCRIBED,
			$this->enrollments->all()[0]->status,
			'the enrollment is unsubscribed rather than mailing a dead address'
		);
	}
}
