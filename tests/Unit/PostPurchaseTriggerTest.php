<?php
/**
 * Post-purchase trigger: order completion enrolls the buyer, idempotently.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FlowForge\Engine\Enroller;
use FlowForge\Engine\FlowTypeEnroller;
use FlowForge\Flow\DefaultFlows;
use FlowForge\Integration\PostPurchaseTrigger;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Support\FixedClock;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PostPurchaseTriggerTest extends TestCase {

	private const T0 = 1_700_000_000;

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private ArrayScheduler $scheduler;
	private PostPurchaseTrigger $trigger;

	protected function setUp(): void {
		Monkey\setUp();

		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->scheduler   = new ArrayScheduler();

		$enroller      = new Enroller( $this->enrollments, $this->scheduler, new FixedClock( self::T0 ) );
		$this->trigger = new PostPurchaseTrigger( new FlowTypeEnroller( $this->flows, $enroller ) );

		Functions\when( 'is_email' )->alias(
			static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	private function seed_flow(): void {
		$this->flows->save( DefaultFlows::post_purchase( FlowRecord::STATUS_ACTIVE ) );
	}

	private function order( string $email ): Mockery\MockInterface {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $email );
		return $order;
	}

	public function test_completed_order_enrolls_buyer(): void {
		$this->seed_flow();
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'buyer@example.com' ) );

		$this->trigger->on_order_completed( 10 );

		$this->assertCount( 1, $this->enrollments->all() );
		$pending = $this->scheduler->pending();
		$this->assertSame( self::T0, $pending[0]['timestamp'], 'thank-you step is immediate (t+0)' );
	}

	public function test_re_completion_is_idempotent(): void {
		$this->seed_flow();
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'buyer@example.com' ) );

		$this->trigger->on_order_completed( 10 );
		$this->trigger->on_order_completed( 10 );

		$this->assertCount( 1, $this->enrollments->all() );
	}

	public function test_no_active_flow_means_no_enrollment(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'buyer@example.com' ) );

		$this->trigger->on_order_completed( 10 );

		$this->assertCount( 0, $this->enrollments->all() );
	}
}
