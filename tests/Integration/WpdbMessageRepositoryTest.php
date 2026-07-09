<?php
/**
 * WpdbMessageRepository against a real $wpdb + the plugin's custom tables. These
 * are the raw-SQL paths the DB-free unit suite can't exercise — the webhook↔
 * message correlation, the (enrollment, step) idempotency key, and the reporting
 * aggregations (incl. the failed count).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Integration;

use CartQuill\Persistence\MessageRecord;
use CartQuill\Persistence\WpdbMessageRepository;
use WP_UnitTestCase;

final class WpdbMessageRepositoryTest extends WP_UnitTestCase {

	private WpdbMessageRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new WpdbMessageRepository();
	}

	private function queued( int $enrollment, int $flow, int $step, string $recipient = 'buyer@example.com' ): MessageRecord {
		return new MessageRecord( null, $enrollment, $flow, $step, $recipient, 'resend', MessageRecord::STATUS_QUEUED );
	}

	/** Persist a settled (past-queued) message for a flow/step with a status. */
	private function settle( int $enrollment, int $flow, int $step, string $status ): void {
		$this->repo->save(
			new MessageRecord( null, $enrollment, $flow, $step, 'buyer@example.com', 'resend', $status, "ext-{$enrollment}-{$step}" )
		);
	}

	public function test_save_and_find_round_trip_through_real_sql(): void {
		$saved = $this->repo->save( $this->queued( 1, 5, 0 ) );
		$this->assertNotNull( $saved->id );

		$found = $this->repo->find( (int) $saved->id );
		$this->assertNotNull( $found );
		$this->assertSame( 'buyer@example.com', $found->recipient );
		$this->assertSame( MessageRecord::STATUS_QUEUED, $found->status );
		$this->assertSame( 5, $found->flow_id );
	}

	public function test_claim_is_idempotent_on_enrollment_and_step(): void {
		$first = $this->repo->claim( $this->queued( 2, 5, 0 ) );
		$this->assertNotNull( $first, 'first claim wins' );

		$second = $this->repo->claim( $this->queued( 2, 5, 0 ) );
		$this->assertNull( $second, 'the unique (enrollment, step) key blocks a double-claim' );
	}

	public function test_find_by_external_id_returns_the_newest_match(): void {
		$this->repo->save( new MessageRecord( null, 3, 5, 0, 'x@e.com', 'resend', MessageRecord::STATUS_SENT, 'ext-dup' ) );
		$newer = $this->repo->save( new MessageRecord( null, 4, 5, 1, 'x@e.com', 'resend', MessageRecord::STATUS_SENT, 'ext-dup' ) );

		$found = $this->repo->find_by_external_id( 'ext-dup' );
		$this->assertNotNull( $found );
		$this->assertSame( (int) $newer->id, (int) $found->id, 'newest row wins (ORDER BY id DESC)' );
		$this->assertNull( $this->repo->find_by_external_id( 'no-such-id' ) );
	}

	public function test_update_status_persists_through_real_sql(): void {
		$saved = $this->repo->save( new MessageRecord( null, 6, 5, 0, 'x@e.com', 'resend', MessageRecord::STATUS_SENT, 'ext-9' ) );

		$this->repo->update_status( (int) $saved->id, MessageRecord::STATUS_BOUNCED );

		$this->assertSame( MessageRecord::STATUS_BOUNCED, $this->repo->find( (int) $saved->id )->status );
	}

	public function test_stats_by_flow_counts_failed_separately_from_sent(): void {
		$this->settle( 10, 5, 0, MessageRecord::STATUS_SENT );
		$this->settle( 10, 5, 1, MessageRecord::STATUS_OPENED );
		$this->settle( 10, 5, 2, MessageRecord::STATUS_CLICKED );
		$this->settle( 10, 5, 3, MessageRecord::STATUS_FAILED );
		$this->settle( 10, 5, 4, MessageRecord::STATUS_QUEUED );

		$stats = $this->repo->stats_by_flow();

		$this->assertSame( 3, $stats[5]['sent'], 'sent excludes failed and queued' );
		$this->assertSame( 2, $stats[5]['opened'], 'a click implies an open' );
		$this->assertSame( 1, $stats[5]['clicked'] );
		$this->assertSame( 1, $stats[5]['failed'], 'the dead-letter is counted apart from sent' );
	}

	public function test_delivery_stats_by_flow_aggregates_the_negative_events(): void {
		$this->settle( 20, 7, 0, MessageRecord::STATUS_DELIVERED );
		$this->settle( 20, 7, 1, MessageRecord::STATUS_OPENED );  // opened implies delivered
		$this->settle( 20, 7, 2, MessageRecord::STATUS_BOUNCED );
		$this->settle( 20, 7, 3, MessageRecord::STATUS_COMPLAINED );

		$delivery = $this->repo->delivery_stats_by_flow();

		$this->assertSame( 2, $delivery[7]['delivered'], 'delivered + opened both count as delivered' );
		$this->assertSame( 1, $delivery[7]['bounced'] );
		$this->assertSame( 1, $delivery[7]['complained'] );
	}
}
