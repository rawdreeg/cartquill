<?php
/**
 * Recipes 4 (Welcome) and 1 (Cart recovery) end-to-end: opt-in gating, the
 * cart-value gate, channel recording, and — crucially — email still sending
 * through SenderInterface, never Mailchimp.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionRegistry;
use CartQuill\Automations\AutomationsRecipes;
use CartQuill\Automations\MailchimpAction;
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
use CartQuill\Tests\Fake\StubMailchimpClient;
use PHPUnit\Framework\TestCase;

final class MailchimpFlowTest extends TestCase {

	private const T0 = 1_700_000_000;

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryMessageRepository $messages;
	private FakeSender $sender;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private FakeCustomerActivity $activity;
	private InMemoryConnectionStore $connections;
	private StubMailchimpClient $mailchimp;
	private Enroller $enroller;

	protected function setUp(): void {
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->messages    = new InMemoryMessageRepository();
		$this->sender      = new FakeSender();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( self::T0 );
		$this->activity    = new FakeCustomerActivity();
		$this->connections = new InMemoryConnectionStore();
		$this->mailchimp   = new StubMailchimpClient();
		$this->enroller    = new Enroller( $this->enrollments, $this->scheduler, $this->clock );

		$this->connections->save(
			new ConnectionRecord(
				null,
				'mailchimp',
				ConnectionRecord::STATUS_CONNECTED,
				array( 'api_key' => 'k-us1', 'server_prefix' => 'us1', 'audience_id' => 'aud' )
			)
		);
	}

	private function runner(): StepRunner {
		$actions = new ActionRegistry();
		$actions->register( new MailchimpAction( $this->connections, $this->mailchimp ) );

		return new StepRunner(
			$this->flows,
			$this->enrollments,
			$this->messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$this->sender,
			new ArraySuppressionList(),
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

	private function drain(): void {
		// Run all steps due now, advancing to flush the abandoned-cart t+1h delay.
		$runner = $this->runner();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->scheduler->run_due( $this->clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );
			$this->clock->advance( 3600 );
		}
	}

	// --- Recipe 4: Welcome ---

	public function test_opted_in_signup_syncs_and_tags_the_member(): void {
		$flow       = $this->active( AutomationsRecipes::account_welcome() );
		$enrollment = $this->enroller->enroll( $flow, 'buyer@example.com', 'account_signup', array( 'marketing_opt_in' => true ) );

		$this->drain();

		$this->assertSame( 1, $this->mailchimp->count() );
		$this->assertSame( 'welcome', $this->mailchimp->last()['tag'] );
		$message = $this->messages->all()[0];
		$this->assertSame( MailchimpAction::TYPE, $message->channel );
		$this->assertSame( MessageRecord::STATUS_SENT, $message->status );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
		$this->assertSame( 'account_signup', $enrollment->source, 'consent source is recorded on the enrollment' );
	}

	public function test_non_opted_in_signup_skips_and_completes_cleanly(): void {
		$flow = $this->active( AutomationsRecipes::account_welcome() );
		$this->enroller->enroll( $flow, 'buyer@example.com', 'account_signup', array( 'marketing_opt_in' => false ) );

		$this->drain();

		$this->assertSame( 0, $this->mailchimp->count(), 'no opt-in -> no sync' );
		$this->assertCount( 0, $this->messages->all() );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	// --- Recipe 1: Cart recovery ---

	public function test_high_value_cart_emails_via_sender_interface_and_syncs(): void {
		$flow = $this->active( AutomationsRecipes::cart_recovery() );
		$this->enroller->enroll( $flow, 'buyer@example.com', 'abandoned_cart', array( 'cart_value' => 75.0 ) );

		$this->drain();

		$this->assertSame( 1, $this->sender->count(), 'the recovery email sends through SenderInterface, not Mailchimp' );
		$this->assertSame( 'You left something behind', $this->sender->last()->subject );
		$this->assertSame( 1, $this->mailchimp->count(), 'and the contact is synced to Mailchimp' );

		$byChannel = array();
		foreach ( $this->messages->all() as $m ) {
			$byChannel[ $m->channel ] = $m;
		}
		$this->assertSame( MessageRecord::CHANNEL_EMAIL, $byChannel['email']->channel );
		$this->assertSame( MailchimpAction::TYPE, $byChannel['mailchimp_sync']->channel );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}

	public function test_low_value_cart_skips_the_recovery_email_but_still_syncs(): void {
		$flow = $this->active( AutomationsRecipes::cart_recovery() );
		$this->enroller->enroll( $flow, 'buyer@example.com', 'abandoned_cart', array( 'cart_value' => 40.0 ) );

		$this->drain();

		$this->assertSame( 0, $this->sender->count(), 'a cart under the threshold skips the recovery email' );
		$this->assertSame( 1, $this->mailchimp->count(), 'the contact is still synced' );
		$this->assertSame( EnrollmentRecord::STATUS_COMPLETED, $this->enrollments->all()[0]->status );
	}
}
