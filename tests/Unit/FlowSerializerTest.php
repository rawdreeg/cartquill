<?php
/**
 * Serializing a stored flow into the uniform JSON shape the builder edits.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Builder\FlowSerializer;
use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\FlowRecord;
use PHPUnit\Framework\TestCase;

final class FlowSerializerTest extends TestCase {

	private function serializer(): FlowSerializer {
		return new FlowSerializer();
	}

	private function flow( array $steps ): FlowRecord {
		return new FlowRecord( 7, 'Cart recovery', 'abandoned_cart', FlowRecord::STATUS_ACTIVE, FlowRecord::SOURCE_TEMPLATE, $steps );
	}

	public function test_serializes_the_flow_envelope(): void {
		$data = $this->serializer()->serialize( $this->flow( array() ) );

		$this->assertSame( 7, $data['id'] );
		$this->assertSame( 'Cart recovery', $data['name'] );
		$this->assertSame( 'abandoned_cart', $data['type'] );
		$this->assertSame( FlowRecord::STATUS_ACTIVE, $data['status'] );
		$this->assertSame( FlowRecord::SOURCE_TEMPLATE, $data['source'] );
		$this->assertSame( array(), $data['steps'] );
	}

	public function test_email_step_normalizes_subject_and_body_into_config(): void {
		$step = new FlowStep( 3600, 'Still thinking?', '<p>Come back</p>', array( array( 'type' => 'exit_if_ordered' ) ) );

		$data = $this->serializer()->serialize( $this->flow( array( $step ) ) );
		$out  = $data['steps'][0];

		$this->assertSame( 3600, $out['delay'] );
		$this->assertSame( 'email', $out['action'] );
		$this->assertSame( array( 'subject' => 'Still thinking?', 'body' => '<p>Come back</p>' ), $out['config'] );
		$this->assertSame( array( array( 'type' => 'exit_if_ordered' ) ), $out['conditions'] );
	}

	public function test_typed_action_step_keeps_its_action_and_config(): void {
		$step = new FlowStep( 0, '', '', array(), 'slack_post', array( 'channel' => '#orders', 'text' => 'Hi {{ customer_email }}' ) );

		$out = $this->serializer()->serialize( $this->flow( array( $step ) ) )['steps'][0];

		$this->assertSame( 'slack_post', $out['action'] );
		$this->assertSame( array( 'channel' => '#orders', 'text' => 'Hi {{ customer_email }}' ), $out['config'] );
	}

	public function test_summarize_gives_a_list_row(): void {
		$flow = $this->flow( array(
			new FlowStep( 0, 'A', 'a' ),
			new FlowStep( 3600, 'B', 'b' ),
		) );

		$summary = $this->serializer()->summarize( $flow );

		$this->assertSame( 7, $summary['id'] );
		$this->assertSame( 'Cart recovery', $summary['name'] );
		$this->assertSame( 'abandoned_cart', $summary['type'] );
		$this->assertSame( FlowRecord::STATUS_ACTIVE, $summary['status'] );
		$this->assertSame( 2, $summary['step_count'] );
	}
}
