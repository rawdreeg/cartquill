<?php
/**
 * Last-touch attribution tested as an observable function of messages + orders.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Attribution\Attributor;
use FlowForge\Persistence\InMemoryAttributionRepository;
use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use PHPUnit\Framework\TestCase;

final class AttributorTest extends TestCase {

	private const ORDER_TS = 1_700_600_000;
	private const WINDOW   = 604800; // 7 days

	private InMemoryMessageRepository $messages;
	private InMemoryAttributionRepository $attributions;
	private Attributor $attributor;

	protected function setUp(): void {
		$this->messages     = new InMemoryMessageRepository();
		$this->attributions = new InMemoryAttributionRepository();
		$this->attributor   = new Attributor( $this->messages, $this->attributions );
	}

	private function sent_message( int $flow_id, string $email, int $sent_ts, string $status = MessageRecord::STATUS_SENT ): void {
		$rec = $this->messages->claim(
			new MessageRecord( null, $flow_id * 10, $flow_id, $flow_id, $email, 'wp_mail', MessageRecord::STATUS_QUEUED )
		);
		$this->messages->save(
			$rec->with_result( $status, 'x', gmdate( 'Y-m-d H:i:s', $sent_ts ) )
		);
	}

	public function test_order_credits_the_most_recent_message_in_window(): void {
		$this->sent_message( 1, 'buyer@example.com', self::ORDER_TS - ( 5 * 86400 ) ); // 5d before
		$this->sent_message( 2, 'buyer@example.com', self::ORDER_TS - ( 1 * 86400 ) ); // 1d before (most recent)

		$record = $this->attributor->attribute( 'buyer@example.com', 900, 49.99, self::ORDER_TS, self::WINDOW );

		$this->assertNotNull( $record );
		$this->assertSame( 2, $record->flow_id, 'last-touch: newest message wins' );
		$this->assertSame( 49.99, $record->revenue );
		$this->assertSame( 49.99, $this->attributions->revenue_by_flow()[2] );
	}

	public function test_message_outside_window_is_not_attributed(): void {
		$this->sent_message( 1, 'buyer@example.com', self::ORDER_TS - ( 8 * 86400 ) ); // 8d before (outside 7d)

		$record = $this->attributor->attribute( 'buyer@example.com', 900, 20.0, self::ORDER_TS, self::WINDOW );

		$this->assertNull( $record );
		$this->assertSame( array(), $this->attributions->revenue_by_flow() );
	}

	public function test_message_to_another_customer_is_ignored(): void {
		$this->sent_message( 1, 'someone@else.com', self::ORDER_TS - 3600 );

		$this->assertNull( $this->attributor->attribute( 'buyer@example.com', 900, 20.0, self::ORDER_TS, self::WINDOW ) );
	}

	public function test_attribution_is_idempotent_per_order(): void {
		$this->sent_message( 1, 'buyer@example.com', self::ORDER_TS - 3600 );

		$first  = $this->attributor->attribute( 'buyer@example.com', 900, 30.0, self::ORDER_TS, self::WINDOW );
		$second = $this->attributor->attribute( 'buyer@example.com', 900, 30.0, self::ORDER_TS, self::WINDOW );

		$this->assertNotNull( $first );
		$this->assertNull( $second, 'same (order, flow) is not double-counted' );
		$this->assertSame( 30.0, $this->attributions->revenue_by_flow()[1] );
	}

	public function test_only_sent_messages_qualify(): void {
		// A queued (never sent) message must not receive attribution.
		$this->messages->claim(
			new MessageRecord( null, 10, 1, 0, 'buyer@example.com', 'wp_mail', MessageRecord::STATUS_QUEUED )
		);

		$this->assertNull( $this->attributor->attribute( 'buyer@example.com', 900, 10.0, self::ORDER_TS, self::WINDOW ) );
	}

	public function test_bounced_message_is_not_eligible_for_attribution(): void {
		// A bounced message never reached the inbox, so it cannot win last-touch.
		$this->sent_message( 1, 'buyer@example.com', self::ORDER_TS - 3600, MessageRecord::STATUS_BOUNCED );

		$this->assertNull( $this->attributor->attribute( 'buyer@example.com', 900, 40.0, self::ORDER_TS, self::WINDOW ) );
	}

	public function test_tie_break_on_equal_sent_at_prefers_the_later_message(): void {
		$ts = self::ORDER_TS - 3600;
		$this->sent_message( 1, 'buyer@example.com', $ts ); // inserted first (lower id)
		$this->sent_message( 2, 'buyer@example.com', $ts ); // same second, inserted later (higher id)

		$record = $this->attributor->attribute( 'buyer@example.com', 900, 15.0, self::ORDER_TS, self::WINDOW );

		$this->assertSame( 2, $record->flow_id, 'higher-id message wins the tie, matching the DB order' );
	}
}
