<?php
/**
 * Reporting aggregation: per-flow sent/opens/clicks + revenue, opens counting
 * clicks, and zero-rows for flows with no activity.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Persistence\InMemoryMessageRepository;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Reporting\FlowReport;
use PHPUnit\Framework\TestCase;

final class FlowReportTest extends TestCase {

	public function test_stats_by_flow_counts_opens_as_open_or_click(): void {
		$messages = new InMemoryMessageRepository();
		$this->record( $messages, 1, MessageRecord::STATUS_SENT );
		$this->record( $messages, 1, MessageRecord::STATUS_OPENED );
		$this->record( $messages, 1, MessageRecord::STATUS_CLICKED );
		$this->record( $messages, 1, MessageRecord::STATUS_QUEUED ); // not counted
		$this->record( $messages, 1, MessageRecord::STATUS_FAILED ); // not counted

		$stats = $messages->stats_by_flow();

		$this->assertSame( 3, $stats[1]['sent'], 'sent = anything past queued/failed' );
		$this->assertSame( 2, $stats[1]['opened'], 'a click implies an open' );
		$this->assertSame( 1, $stats[1]['clicked'] );
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

	private function record( InMemoryMessageRepository $repo, int $flow_id, string $status ): void {
		static $enrollment = 0;
		++$enrollment;
		$rec = $repo->claim(
			new MessageRecord( null, $enrollment, $flow_id, 0, 'a@b.com', 'wp_mail', MessageRecord::STATUS_QUEUED )
		);
		$repo->update_status( (int) $rec->id, $status );
	}

	private function flow( int $id, string $name ): \FlowForge\Persistence\FlowRecord {
		return new \FlowForge\Persistence\FlowRecord( $id, $name, 'welcome', 'active', 'template', array() );
	}
}
