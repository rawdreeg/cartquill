<?php
/**
 * Flow step JSON (de)serialization and definition conversion.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\FlowRecord;
use PHPUnit\Framework\TestCase;

final class FlowRecordTest extends TestCase {

	protected function setUp(): void {
		Monkey\setUp();
		// steps_json() encodes via wp_json_encode; delegate to native json.
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_steps_survive_a_json_round_trip(): void {
		$record = new FlowRecord(
			id: 1,
			name: 'Abandoned cart',
			type: 'abandoned_cart',
			status: FlowRecord::STATUS_ACTIVE,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array(
				new FlowStep( 3600, 'Come back', 'body one' ),
				new FlowStep( 86400, 'Still here?', 'body two', array( array( 'type' => 'exit_if_ordered' ) ) ),
			),
		);

		$decoded = FlowRecord::decode_steps( $record->steps_json() );

		$this->assertCount( 2, $decoded );
		$this->assertSame( 3600, $decoded[0]->delay );
		$this->assertSame( 'Come back', $decoded[0]->subject );
		$this->assertSame( 86400, $decoded[1]->delay );
		$this->assertSame( array( array( 'type' => 'exit_if_ordered' ) ), $decoded[1]->conditions );
	}

	public function test_decode_handles_empty_and_invalid_json(): void {
		$this->assertSame( array(), FlowRecord::decode_steps( null ) );
		$this->assertSame( array(), FlowRecord::decode_steps( '' ) );
		$this->assertSame( array(), FlowRecord::decode_steps( 'not json' ) );
	}

	public function test_to_definition_exposes_steps(): void {
		$record = new FlowRecord(
			id: 5,
			name: 'Welcome',
			type: 'welcome',
			status: FlowRecord::STATUS_ACTIVE,
			source: FlowRecord::SOURCE_TEMPLATE,
			steps: array( new FlowStep( 0, 'Hi', 'body' ) ),
		);

		$definition = $record->to_definition();
		$this->assertSame( 5, $definition->id );
		$this->assertSame( 1, $definition->step_count() );
		$this->assertSame( 'Hi', $definition->step( 0 )->subject );
	}
}
