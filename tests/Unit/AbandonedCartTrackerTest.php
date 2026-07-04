<?php
/**
 * Email capture and recovery at the WooCommerce boundary.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FlowForge\Integration\AbandonedCartTracker;
use FlowForge\Persistence\CartCaptureRecord;
use FlowForge\Persistence\InMemoryCartCaptureStore;
use FlowForge\Support\FixedClock;
use Mockery;
use PHPUnit\Framework\TestCase;

final class AbandonedCartTrackerTest extends TestCase {

	private InMemoryCartCaptureStore $captures;
	private AbandonedCartTracker $tracker;

	protected function setUp(): void {
		Monkey\setUp();
		$this->captures = new InMemoryCartCaptureStore();
		$this->tracker  = new AbandonedCartTracker( $this->captures, new FixedClock( 1_700_000_000 ) );

		Functions\when( 'is_email' )->alias(
			static fn( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_capture_records_a_pending_cart(): void {
		$this->tracker->capture( 'Buyer@Example.com' );

		$record = $this->captures->find( 'buyer@example.com' );
		$this->assertNotNull( $record );
		$this->assertSame( CartCaptureRecord::STATUS_PENDING, $record->status );
		$this->assertSame( '2023-11-14 22:13:20', $record->updated_at );
	}

	public function test_invalid_email_is_ignored(): void {
		$this->tracker->capture( 'not-an-email' );
		$this->assertNull( $this->captures->find( 'not-an-email' ) );
	}

	public function test_logged_in_add_to_cart_captures_account_email(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$user             = new \stdClass();
		$user->user_email = 'member@example.com';
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		$this->tracker->on_add_to_cart();

		$this->assertNotNull( $this->captures->find( 'member@example.com' ) );
	}

	public function test_guest_add_to_cart_captures_nothing(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->tracker->on_add_to_cart();
		$this->assertNull( $this->captures->find( 'someone@example.com' ) );
	}

	public function test_checkout_update_captures_billing_email(): void {
		$this->tracker->on_checkout_update( 'billing_first_name=Jo&billing_email=guest%40example.com&x=1' );
		$this->assertNotNull( $this->captures->find( 'guest@example.com' ) );
	}

	public function test_order_placed_marks_capture_recovered(): void {
		$this->tracker->capture( 'buyer@example.com' );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( 'buyer@example.com' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->tracker->on_order_placed( 55 );

		$this->assertSame(
			CartCaptureRecord::STATUS_RECOVERED,
			$this->captures->find( 'buyer@example.com' )->status
		);
	}
}
