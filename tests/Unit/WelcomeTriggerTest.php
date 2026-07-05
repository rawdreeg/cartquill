<?php
/**
 * Welcome trigger: first order or newsletter signup enrolls; returning
 * customers are not re-welcomed.
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
use FlowForge\Integration\WelcomeTrigger;
use FlowForge\Persistence\FlowRecord;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryFlowRepository;
use FlowForge\Scheduling\ArrayScheduler;
use FlowForge\Support\FixedClock;
use FlowForge\Tests\Fake\FakeCustomerActivity;
use Mockery;
use PHPUnit\Framework\TestCase;

final class WelcomeTriggerTest extends TestCase {

	private const T0 = 1_700_000_000;

	private InMemoryFlowRepository $flows;
	private InMemoryEnrollmentRepository $enrollments;
	private ArrayScheduler $scheduler;
	private FakeCustomerActivity $activity;
	private WelcomeTrigger $trigger;

	protected function setUp(): void {
		Monkey\setUp();

		$this->flows       = new InMemoryFlowRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();
		$this->scheduler   = new ArrayScheduler();
		$this->activity    = new FakeCustomerActivity();

		$enroller      = new Enroller( $this->enrollments, $this->scheduler, new FixedClock( self::T0 ) );
		$type_enroller = new FlowTypeEnroller( $this->flows, $enroller );
		$this->trigger = new WelcomeTrigger( $type_enroller, $this->activity );

		$this->flows->save( DefaultFlows::welcome( FlowRecord::STATUS_ACTIVE ) );

		Functions\when( 'is_email' )->alias(
			static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	private function order( string $email ): Mockery\MockInterface {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $email );
		return $order;
	}

	public function test_first_order_enrolls_and_schedules_immediate_step(): void {
		$this->activity->record_order( 'buyer@example.com', self::T0 ); // this is their 1st order
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'buyer@example.com' ) );

		$this->trigger->on_order( 1 );

		$this->assertCount( 1, $this->enrollments->all() );
		$pending = $this->scheduler->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( self::T0, $pending[0]['timestamp'], 'welcome step 1 is immediate' );
	}

	public function test_order_enrollment_is_registered_on_checkout_order_processed(): void {
		$this->trigger->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_order_processed', array( $this->trigger, 'on_order' ) ),
			'enrolls when the order is created, so off-site/admin/API orders are covered'
		);
		$this->assertFalse(
			has_action( 'woocommerce_thankyou', array( $this->trigger, 'on_order' ) ),
			'no longer bound to the display-time thank-you hook'
		);
	}

	public function test_a_hook_refire_does_not_double_enroll(): void {
		$this->activity->record_order( 'buyer@example.com', self::T0 );
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'buyer@example.com' ) );

		$this->trigger->on_order( 1 );
		$this->trigger->on_order( 1 ); // woocommerce_checkout_order_processed can fire again

		$this->assertCount( 1, $this->enrollments->all(), 'a re-fire does not create a second welcome enrollment' );
	}

	public function test_returning_customer_is_not_welcomed(): void {
		$this->activity->record_order( 'buyer@example.com', self::T0 - 1000 );
		$this->activity->record_order( 'buyer@example.com', self::T0 ); // 2nd order
		Functions\when( 'wc_get_order' )->justReturn( $this->order( 'buyer@example.com' ) );

		$this->trigger->on_order( 1 );

		$this->assertCount( 0, $this->enrollments->all() );
	}

	public function test_newsletter_signup_enrolls(): void {
		$this->trigger->on_newsletter_signup( 'subscriber@example.com' );

		$this->assertCount( 1, $this->enrollments->all() );
		$this->assertSame( 'subscriber@example.com', $this->enrollments->all()[0]->customer_email );
	}

	public function test_invalid_signup_email_is_ignored(): void {
		$this->trigger->on_newsletter_signup( 'nope' );
		$this->assertCount( 0, $this->enrollments->all() );
	}
}
