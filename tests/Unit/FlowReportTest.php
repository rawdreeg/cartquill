<?php
/**
 * Reporting aggregation: per-flow sent/opens/clicks + revenue, opens counting
 * clicks, and zero-rows for flows with no activity.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Persistence\MessageRecord;
use CartQuill\Reporting\FlowReport;
use PHPUnit\Framework\TestCase;

final class FlowReportTest extends TestCase {

	public function test_stats_by_flow_counts_opens_as_open_or_click(): void {
		$messages = new InMemoryMessageRepository();
		$this->record( $messages, 1, MessageRecord::STATUS_SENT );
		$this->record( $messages, 1, MessageRecord::STATUS_OPENED );
		$this->record( $messages, 1, MessageRecord::STATUS_CLICKED );
		$this->record( $messages, 1, MessageRecord::STATUS_QUEUED ); // not counted
		$this->record( $messages, 1, MessageRecord::STATUS_FAILED ); // counted as failed, not sent

		$stats = $messages->stats_by_flow();

		$this->assertSame( 3, $stats[1]['sent'], 'sent = anything past queued/failed' );
		$this->assertSame( 2, $stats[1]['opened'], 'a click implies an open' );
		$this->assertSame( 1, $stats[1]['clicked'] );
		$this->assertSame( 1, $stats[1]['failed'], 'dead-lettered sends are counted separately from sent' );
	}

	public function test_build_produces_a_row_per_flow_with_zeros_where_idle(): void {
		$flows = array(
			$this->flow( 1, 'Welcome' ),
			$this->flow( 2, 'Win-back' ),
		);
		$stats   = array( 1 => array( 'sent' => 10, 'opened' => 4, 'clicked' => 2 ) );
		$revenue = array( 1 => 150.0 );

		$report = new FlowReport();
		$rows   = $report->build( $flows, $stats, $revenue );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Welcome', $rows[0]->name );
		$this->assertSame( 10, $rows[0]->sent );
		$this->assertSame( 150.0, $rows[0]->revenue );
		// Idle flow renders zeros, not absent.
		$this->assertSame( 'Win-back', $rows[1]->name );
		$this->assertSame( 0, $rows[1]->sent );
		$this->assertSame( 0.0, $rows[1]->revenue );

		$this->assertSame( 150.0, $report->total_revenue( $rows ) );
	}

	public function test_delivery_stats_fold_engagement_into_delivered_and_count_negatives(): void {
		$messages = new InMemoryMessageRepository();
		$this->record( $messages, 1, MessageRecord::STATUS_DELIVERED );
		$this->record( $messages, 1, MessageRecord::STATUS_OPENED );  // opened implies delivered
		$this->record( $messages, 1, MessageRecord::STATUS_CLICKED ); // clicked implies delivered
		$this->record( $messages, 1, MessageRecord::STATUS_BOUNCED );
		$this->record( $messages, 1, MessageRecord::STATUS_COMPLAINED );
		$this->record( $messages, 1, MessageRecord::STATUS_SENT );    // not yet confirmed

		$delivery = $messages->delivery_stats_by_flow();

		$this->assertSame( 3, $delivery[1]['delivered'], 'delivered/opened/clicked all count as delivered' );
		$this->assertSame( 1, $delivery[1]['bounced'] );
		$this->assertSame( 1, $delivery[1]['complained'] );
	}

	public function test_build_maps_delivery_stats_onto_rows(): void {
		$flows    = array( $this->flow( 1, 'Welcome' ) );
		$stats    = array( 1 => array( 'sent' => 10, 'opened' => 4, 'clicked' => 2 ) );
		$delivery = array( 1 => array( 'delivered' => 9, 'bounced' => 1, 'complained' => 1 ) );

		$rows = ( new FlowReport() )->build( $flows, $stats, array(), $delivery );

		$this->assertSame( 9, $rows[0]->delivered );
		$this->assertSame( 1, $rows[0]->bounced );
		$this->assertSame( 1, $rows[0]->complained );
	}

	public function test_build_defaults_delivery_to_zero_without_the_addon(): void {
		$rows = ( new FlowReport() )->build( array( $this->flow( 1, 'Welcome' ) ), array(), array() );

		$this->assertSame( 0, $rows[0]->delivered );
		$this->assertSame( 0, $rows[0]->bounced );
		$this->assertSame( 0, $rows[0]->complained );
	}

	public function test_build_maps_the_failed_count_onto_rows(): void {
		$flows = array( $this->flow( 1, 'Welcome' ) );
		$stats = array( 1 => array( 'sent' => 10, 'opened' => 4, 'clicked' => 2, 'failed' => 3 ) );

		$rows = ( new FlowReport() )->build( $flows, $stats, array() );

		$this->assertSame( 3, $rows[0]->failed );
	}

	public function test_build_defaults_failed_to_zero_when_stats_omit_it(): void {
		$stats = array( 1 => array( 'sent' => 1, 'opened' => 0, 'clicked' => 0 ) );

		$rows = ( new FlowReport() )->build( array( $this->flow( 1, 'Welcome' ) ), $stats, array() );

		$this->assertSame( 0, $rows[0]->failed, 'stats without a failed key default to 0' );
	}

	public function test_totals_sums_every_metric_across_rows(): void {
		$flows    = array( $this->flow( 1, 'Welcome' ), $this->flow( 2, 'Win-back' ) );
		$stats    = array(
			1 => array( 'sent' => 10, 'opened' => 4, 'clicked' => 2, 'failed' => 1 ),
			2 => array( 'sent' => 5, 'opened' => 1, 'clicked' => 0, 'failed' => 2 ),
		);
		$revenue  = array( 1 => 150.0, 2 => 30.0 );
		$delivery = array(
			1 => array( 'delivered' => 9, 'bounced' => 1, 'complained' => 0 ),
			2 => array( 'delivered' => 4, 'bounced' => 1, 'complained' => 1 ),
		);

		$report = new FlowReport();
		$totals = $report->totals( $report->build( $flows, $stats, $revenue, $delivery ) );

		$this->assertSame( 15, $totals['sent'] );
		$this->assertSame( 5, $totals['opened'] );
		$this->assertSame( 2, $totals['clicked'] );
		$this->assertSame( 180.0, $totals['revenue'] );
		$this->assertSame( 13, $totals['delivered'] );
		$this->assertSame( 2, $totals['bounced'] );
		$this->assertSame( 1, $totals['complained'] );
		$this->assertSame( 3, $totals['failed'] );
	}

	public function test_totals_are_zero_for_no_rows(): void {
		$totals = ( new FlowReport() )->totals( array() );

		$this->assertSame( 0, $totals['sent'] );
		$this->assertSame( 0, $totals['opened'] );
		$this->assertSame( 0, $totals['clicked'] );
		$this->assertSame( 0.0, $totals['revenue'] );
		$this->assertSame( 0, $totals['failed'] );
	}

	private function record( InMemoryMessageRepository $repo, int $flow_id, string $status ): void {
		static $enrollment = 0;
		++$enrollment;
		$rec = $repo->claim(
			new MessageRecord( null, $enrollment, $flow_id, 0, 'a@b.com', 'wp_mail', MessageRecord::STATUS_QUEUED )
		);
		$repo->update_status( (int) $rec->id, $status );
	}

	private function flow( int $id, string $name ): \CartQuill\Persistence\FlowRecord {
		return new \CartQuill\Persistence\FlowRecord( $id, $name, 'welcome', 'active', 'template', array() );
	}
}
