<?php
/**
 * Drives the welcome and post-purchase flows through the engine (FakeSender)
 * to prove both steps fire at the right delays and the consent source sticks.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\FlowTypeEnroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Flow\Renderer;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Sender\FakeSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use PHPUnit\Framework\TestCase;

final class WelcomePostPurchaseFlowTest extends TestCase {

	private const T0 = 1_700_000_000;

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private FakeSender $sender;
	private ArrayScheduler $scheduler;
	private FixedClock $clock;
	private FlowTypeEnroller $type_enroller;
	private StepRunner $runner;

	protected function setUp(): void {
		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->sender      = new FakeSender();
		$this->scheduler   = new ArrayScheduler();
		$this->clock       = new FixedClock( self::T0 );

		$enroller            = new Enroller( $this->enrollments, $this->scheduler, $this->clock );
		$this->type_enroller = new FlowTypeEnroller( $this->flows, $enroller );
		$this->runner        = new StepRunner(
			$this->flows,
			$this->enrollments,
			new InMemoryMessageRepository(),
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$this->sender,
			new ArraySuppressionList(),
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$this->scheduler,
			$this->clock,
		);
	}

	private function tick(): void {
		$this->scheduler->run_due(
			$this->clock->now(),
			fn( int $e, int $s ) => $this->runner->run_step( $e, $s )
		);
	}

	public function test_welcome_sends_immediately_then_after_three_days(): void {
		$this->flows->save( DefaultFlows::welcome( FlowRecord::STATUS_ACTIVE ) );
		$this->type_enroller->enroll( DefaultFlows::TYPE_WELCOME, 'member@example.com', 'newsletter' );

		// Immediate step.
		$this->tick();
		$this->assertSame( 1, $this->sender->count() );
		$this->assertSame( 'Welcome to Acme', $this->sender->last()->subject );

		// t+3d follow-up is scheduled, not yet due.
		$pending = $this->scheduler->pending();
		$this->assertSame( self::T0 + 259200, $pending[0]['timestamp'] );

		$this->clock->advance( 259200 );
		$this->tick();
		$this->assertSame( 2, $this->sender->count() );
		$this->assertSame( 'Getting the most out of Acme', $this->sender->last()->subject );

		// Consent source was recorded on the enrollment.
		$this->assertSame( 'newsletter', $this->enrollments->all()[0]->source );
	}

	public function test_post_purchase_sends_at_t0_then_fourteen_days(): void {
		$this->flows->save( DefaultFlows::post_purchase( FlowRecord::STATUS_ACTIVE ) );
		$this->type_enroller->enroll( DefaultFlows::TYPE_POST_PURCHASE, 'buyer@example.com', 'post_purchase' );

		$this->tick();
		$this->assertSame( 1, $this->sender->count() );
		$this->assertSame( 'Thanks for your order', $this->sender->last()->subject );

		$pending = $this->scheduler->pending();
		$this->assertSame( self::T0 + 1209600, $pending[0]['timestamp'], 't+14d cross-sell' );

		$this->clock->advance( 1209600 );
		$this->tick();
		$this->assertSame( 2, $this->sender->count() );
		$this->assertSame( 'post_purchase', $this->enrollments->all()[0]->source );
	}
}
