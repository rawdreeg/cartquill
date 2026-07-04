<?php
/**
 * The built-in flow definitions have the documented types and step timing.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Flow\DefaultFlows;
use FlowForge\Persistence\FlowRecord;
use PHPUnit\Framework\TestCase;

final class DefaultFlowsTest extends TestCase {

	public function test_welcome_flow_is_immediate_then_three_days(): void {
		$flow = DefaultFlows::welcome();

		$this->assertSame( DefaultFlows::TYPE_WELCOME, $flow->type );
		$this->assertSame( FlowRecord::STATUS_DRAFT, $flow->status );
		$this->assertSame( array( 0, 259200 ), array_map( static fn( $s ) => $s->delay, $flow->steps ) );
	}

	public function test_post_purchase_flow_is_immediate_then_fourteen_days(): void {
		$flow = DefaultFlows::post_purchase();

		$this->assertSame( DefaultFlows::TYPE_POST_PURCHASE, $flow->type );
		$this->assertSame( array( 0, 1209600 ), array_map( static fn( $s ) => $s->delay, $flow->steps ) );
	}

	public function test_abandoned_cart_flow_has_exit_conditions(): void {
		$flow = DefaultFlows::abandoned_cart();

		$this->assertSame( DefaultFlows::TYPE_ABANDONED_CART, $flow->type );
		$this->assertSame( array( 3600, 86400 ), array_map( static fn( $s ) => $s->delay, $flow->steps ) );
		foreach ( $flow->steps as $step ) {
			$this->assertSame( array( array( 'type' => 'exit_if_ordered' ) ), $step->conditions );
		}
	}

	public function test_win_back_flow_has_exit_conditions_and_follow_up(): void {
		$flow = DefaultFlows::win_back();

		$this->assertSame( DefaultFlows::TYPE_WIN_BACK, $flow->type );
		$this->assertSame( array( 0, 604800 ), array_map( static fn( $s ) => $s->delay, $flow->steps ) );
		foreach ( $flow->steps as $step ) {
			$this->assertSame( array( array( 'type' => 'exit_if_ordered' ) ), $step->conditions );
		}
	}

	public function test_status_override_is_applied(): void {
		$this->assertTrue( DefaultFlows::welcome( FlowRecord::STATUS_ACTIVE )->is_active() );
	}
}
