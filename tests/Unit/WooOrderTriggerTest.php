<?php
/**
 * Integration-style test of the actual Woo hook path:
 * woocommerce_thankyou -> create enrollment -> send -> record message.
 *
 * Exercises WooOrderTrigger with real collaborators (SpineDispatcher, FakeSender,
 * in-memory repositories); only the WooCommerce boundary (wc_get_order, is_email,
 * the order object) is faked.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FlowForge\Engine\SpineDispatcher;
use FlowForge\Flow\Renderer;
use FlowForge\Integration\WooOrderTrigger;
use FlowForge\Persistence\InMemoryEnrollmentRepository;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Sender\FakeSender;
use FlowForge\Settings\ArraySettings;
use FlowForge\Support\FixedClock;
use Mockery;
use PHPUnit\Framework\TestCase;

final class WooOrderTriggerTest extends TestCase {

	private FakeSender $sender;
	private InMemoryMessageRepository $messages;
	private InMemoryEnrollmentRepository $enrollments;
	private WooOrderTrigger $trigger;

	protected function setUp(): void {
		Monkey\setUp();

		$this->sender      = new FakeSender();
		$this->messages    = new InMemoryMessageRepository();
		$this->enrollments = new InMemoryEnrollmentRepository();

		$dispatcher = new SpineDispatcher(
			$this->sender,
			$this->messages,
			new Renderer(),
			new ArraySettings( 'Acme Store', 'hello@acme.test' ),
			new FixedClock( 1_700_000_000 ),
		);

		$this->trigger = new WooOrderTrigger( $dispatcher, $this->enrollments );

		Functions\when( 'is_email' )->alias(
			static fn( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	/**
	 * @param array<string, mixed> $meta Existing order meta.
	 */
	private function fake_order( string $email, array $meta = array() ): Mockery\MockInterface {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $email );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static fn( $key ) => $meta[ $key ] ?? ''
		);
		$order->shouldReceive( 'update_meta_data' )->andReturnNull();
		$order->shouldReceive( 'save' )->andReturnNull();
		return $order;
	}

	public function test_order_received_enrolls_and_sends_one_email(): void {
		$order = $this->fake_order( 'buyer@example.com' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->trigger->on_order_received( 123 );

		// One enrollment created for the buyer.
		$this->assertCount( 1, $this->enrollments->all() );
		$this->assertSame( 'buyer@example.com', $this->enrollments->all()[0]->customer_email );

		// One email sent...
		$this->assertSame( 1, $this->sender->count() );

		// ...and one message row recorded, linked to the enrollment.
		$rows = $this->messages->all();
		$this->assertCount( 1, $rows );
		$this->assertSame( MessageRecord::STATUS_SENT, $rows[0]->status );
		$this->assertSame( $this->enrollments->all()[0]->id, $rows[0]->enrollment_id );
	}

	public function test_already_sent_orders_are_skipped(): void {
		$order = $this->fake_order( 'buyer@example.com', array( '_flowforge_spine_sent' => '1' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->trigger->on_order_received( 123 );

		$this->assertSame( 0, $this->sender->count() );
		$this->assertCount( 0, $this->enrollments->all() );
	}

	public function test_missing_order_is_a_no_op(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->trigger->on_order_received( 999 );

		$this->assertSame( 0, $this->sender->count() );
	}

	public function test_order_without_valid_email_is_a_no_op(): void {
		$order = $this->fake_order( 'not-an-email' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->trigger->on_order_received( 123 );

		$this->assertSame( 0, $this->sender->count() );
		$this->assertCount( 0, $this->enrollments->all() );
	}
}
