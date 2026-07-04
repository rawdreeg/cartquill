<?php
/**
 * Unsubscribe: a valid link suppresses the address and unsubscribes its
 * enrollments, and the engine then never sends to it again.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Compliance\ArraySuppressionList;
use FlowForge\Compliance\UnsubscribeEndpoint;
use FlowForge\Compliance\UnsubscribeLink;
use FlowForge\Engine\ConditionEvaluator;
use FlowForge\Engine\Enroller;
use FlowForge\Engine\MessageComposer;
use FlowForge\Engine\StepRunner;
use FlowForge\Flow\DefaultFlows;
use FlowForge\Flow\Renderer;
use FlowForge\Persistence\EnrollmentRecord;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Sender\FakeSender;
use FlowForge\Settings\ArraySettings;
use FlowForge\Support\FixedClock;
use FlowForge\Tests\Fake\FakeCustomerActivity;
use FlowForge\Tracking\Signer;
use PHPUnit\Framework\TestCase;

final class UnsubscribeTest extends TestCase {

	private const NOW = 1_700_000_000;

	private Signer $signer;
	private UnsubscribeLink $link;
	private ArraySuppressionList $suppression;
	private InMemoryEnrollmentRepository $enrollments;
	private UnsubscribeEndpoint $endpoint;

	protected function setUp(): void {
		$this->signer      = new Signer( 'secret' );
		$this->link        = new UnsubscribeLink( 'https://shop.test/', $this->signer );
		$this->suppression = new ArraySuppressionList();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->endpoint    = new UnsubscribeEndpoint( $this->signer, $this->link, $this->suppression, $this->enrollments );
	}

	public function test_valid_unsubscribe_suppresses_and_unsubscribes_enrollments(): void {
		$this->enrollments->save( new EnrollmentRecord( null, 1, 'buyer@example.com', EnrollmentRecord::STATUS_ACTIVE ) );
		$token = $this->signer->sign( $this->link->payload( 'buyer@example.com' ) );

		$this->assertTrue( $this->endpoint->handle( 'buyer@example.com', $token ) );
		$this->assertTrue( $this->suppression->is_suppressed( 'buyer@example.com' ) );
		$this->assertSame( EnrollmentRecord::STATUS_UNSUBSCRIBED, $this->enrollments->all()[0]->status );
	}

	public function test_forged_token_does_nothing(): void {
		$this->enrollments->save( new EnrollmentRecord( null, 1, 'buyer@example.com', EnrollmentRecord::STATUS_ACTIVE ) );

		$this->assertFalse( $this->endpoint->handle( 'buyer@example.com', 'forged' ) );
		$this->assertFalse( $this->suppression->is_suppressed( 'buyer@example.com' ) );
		$this->assertSame( EnrollmentRecord::STATUS_ACTIVE, $this->enrollments->all()[0]->status );
	}

	public function test_unsubscribed_address_is_never_sent_to_again(): void {
		// Build a full engine and prove the pre-send suppression check honors it.
		$flows     = new InMemoryFlowRepository();
		$messages  = new InMemoryMessageRepository();
		$sender    = new FakeSender();
		$scheduler = new ArrayScheduler();
		$clock     = new FixedClock( self::NOW );

		$flow = $flows->save( DefaultFlows::welcome( FlowRecord::STATUS_ACTIVE ) );

		$enroller = new Enroller( $this->enrollments, $scheduler, $clock );
		$runner   = new StepRunner(
			$flows,
			$this->enrollments,
			$messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ), null, $this->link ),
			$sender,
			$this->suppression,
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$scheduler,
			$clock,
		);

		$enroller->enroll( $flow, 'buyer@example.com', 'newsletter' );

		// Customer unsubscribes before the step runs.
		$token = $this->signer->sign( $this->link->payload( 'buyer@example.com' ) );
		$this->endpoint->handle( 'buyer@example.com', $token );

		$scheduler->run_due( $clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );

		$this->assertSame( 0, $sender->count(), 'suppression is honored before the send' );
	}

	public function test_composed_email_carries_the_http_unsubscribe_link(): void {
		$composer = new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ), null, $this->link );
		$message  = $composer->compose(
			new \FlowForge\Flow\FlowStep( 0, 'Hi', '<p>Body</p>' ),
			'buyer@example.com',
			1,
			0,
			1
		);

		$this->assertStringContainsString( UnsubscribeLink::PARAM, $message->body );
		$this->assertStringContainsString( 'https://shop.test/', (string) $message->unsubscribe );
	}
}
