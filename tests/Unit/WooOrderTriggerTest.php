<?php
/**
 * Integration-style test of the order-placed trigger:
 * woocommerce_thankyou -> enroll buyer into active post_purchase flows.
 *
 * Exercises WooOrderTrigger with the real Enroller and in-memory repositories;
 * only the WooCommerce boundary (wc_get_order, is_email, the order object) is
 * faked.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FlowForge\Engine\Enroller;
use FlowForge\Flow\FlowStep;
use FlowForge\Integration\WooOrderTrigger;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Support\FixedClock;
use Mockery;
use PHPUnit\Framework\TestCase;

final class WooOrderTriggerTest extends TestCase {

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private ArrayScheduler $scheduler;
	private WooOrderTrigger $trigger;

	protected function setUp(): void {
		Monkey\setUp();

		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->scheduler   = new ArrayScheduler();

		$enroller = new Enroller( $this->enrollments, $this->scheduler, new FixedClock( 1_700_000_000 ) );

		$this->trigger = new WooOrderTrigger( $enroller, $this->flows );

		Functions\when( 'is_email' )->alias(
			static fn( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	private function seed_active_post_purchase_flow(): void {
		$this->flows->save(
			new FlowRecord(
				id: null,
				name: 'Post-purchase',
				type: 'post_purchase',
				status: FlowRecord::STATUS_ACTIVE,
				source: FlowRecord::SOURCE_TEMPLATE,
				steps: array( new FlowStep( 0, 'Thanks', 'body' ) ),
			)
		);
	}

	private function fake_order( string $email ): Mockery\MockInterface {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $email );
		return $order;
	}

	public function test_order_enrolls_buyer_into_active_post_purchase_flow(): void {
		$this->seed_active_post_purchase_flow();
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 'buyer@example.com' ) );

		$this->trigger->on_order_received( 123 );

		$this->assertCount( 1, $this->enrollments->all() );
		$this->assertSame( 'buyer@example.com', $this->enrollments->all()[0]->customer_email );
		$this->assertCount( 1, $this->scheduler->pending() );
	}

	public function test_double_fire_enrolls_only_once(): void {
		$this->seed_active_post_purchase_flow();
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 'buyer@example.com' ) );

		$this->trigger->on_order_received( 123 );
		$this->trigger->on_order_received( 123 );

		$this->assertCount( 1, $this->enrollments->all(), 'idempotent enrollment' );
	}

	public function test_no_active_flow_means_no_enrollment(): void {
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 'buyer@example.com' ) );

		$this->trigger->on_order_received( 123 );

		$this->assertCount( 0, $this->enrollments->all() );
	}

	public function test_missing_order_is_a_no_op(): void {
		$this->seed_active_post_purchase_flow();
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->trigger->on_order_received( 999 );

		$this->assertCount( 0, $this->enrollments->all() );
	}

	public function test_invalid_email_is_a_no_op(): void {
		$this->seed_active_post_purchase_flow();
		Functions\when( 'wc_get_order' )->justReturn( $this->fake_order( 'not-an-email' ) );

		$this->trigger->on_order_received( 123 );

		$this->assertCount( 0, $this->enrollments->all() );
	}
}
