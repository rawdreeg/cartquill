<?php
/**
 * The flow editor's plan-gate enforcement at save: a disallowed activation is
 * kept out of the active status while the edits are preserved, and conditional
 * logic is gated on every save (not only the first activation).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Admin\FlowEditorPage;
use CartQuill\Flow\FlowEditor;
use CartQuill\Flow\FlowStep;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\PlanGate;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryFlowRepository;
use PHPUnit\Framework\TestCase;

final class FlowEditorPageTest extends TestCase {

	private function page( string $tier, InMemoryFlowRepository $flows ): FlowEditorPage {
		return new FlowEditorPage(
			$flows,
			new FlowEditor(),
			new PlanGate( new ArrayLicense( array( $tier ), Plans::entitlements( $tier ) ), $flows )
		);
	}

	private function flow( ?int $id, string $status, array $steps = array() ): FlowRecord {
		return new FlowRecord( $id, 'Flow', 'post_purchase', $status, FlowRecord::SOURCE_TEMPLATE, $steps );
	}

	private function branching_step(): FlowStep {
		return new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'cart_value_gt', 'value' => 50 ) ) );
	}

	public function test_allowed_activation_saves_active(): void {
		$flows = new InMemoryFlowRepository();
		$page  = $this->page( Plans::STARTER, $flows );

		$current   = $this->flow( 1, FlowRecord::STATUS_DRAFT );
		$candidate = $current->with_status( FlowRecord::STATUS_ACTIVE );

		$gated = $page->gate_save( $current, $candidate );

		$this->assertSame( '', $gated['blocked'] );
		$this->assertTrue( $gated['record']->is_active() );
	}

	public function test_over_cap_activation_reverts_to_prior_status_and_keeps_edits(): void {
		$flows = new InMemoryFlowRepository();
		for ( $i = 0; $i < 5; $i++ ) {
			$flows->save( $this->flow( null, FlowRecord::STATUS_ACTIVE ) ); // Starter cap 5.
		}
		$page = $this->page( Plans::STARTER, $flows );

		$current   = $this->flow( 6, FlowRecord::STATUS_DRAFT );
		$candidate = new FlowRecord( 6, 'Renamed', 'post_purchase', FlowRecord::STATUS_ACTIVE, FlowRecord::SOURCE_TEMPLATE, array() );

		$gated = $page->gate_save( $current, $candidate );

		$this->assertSame( PlanGate::REASON_WORKFLOW_CAP, $gated['blocked'] );
		$this->assertSame( FlowRecord::STATUS_DRAFT, $gated['record']->status, 'not activated' );
		$this->assertSame( 'Renamed', $gated['record']->name, 'the edit is preserved' );
	}

	public function test_adding_conditional_logic_to_an_active_flow_pauses_it(): void {
		// The loophole: activate a plain flow (allowed on Starter), then re-save it
		// with a branching condition. It must not keep running active.
		$flows   = new InMemoryFlowRepository();
		$current = $flows->save( $this->flow( null, FlowRecord::STATUS_ACTIVE ) );
		$page    = $this->page( Plans::STARTER, $flows );

		$candidate = $current->is_active()
			? new FlowRecord( $current->id, $current->name, $current->type, FlowRecord::STATUS_ACTIVE, $current->source, array( $this->branching_step() ) )
			: $current;

		$gated = $page->gate_save( $current, $candidate );

		$this->assertSame( PlanGate::REASON_CONDITIONAL_LOGIC, $gated['blocked'] );
		$this->assertSame( FlowRecord::STATUS_PAUSED, $gated['record']->status, 'paused so the condition cannot run' );
		$this->assertCount( 1, $gated['record']->steps, 'the edit is preserved' );
	}

	public function test_plain_resave_of_an_active_flow_within_cap_is_untouched(): void {
		$flows   = new InMemoryFlowRepository();
		$current = $flows->save( $this->flow( null, FlowRecord::STATUS_ACTIVE ) );
		$page    = $this->page( Plans::STARTER, $flows );

		$candidate = new FlowRecord( $current->id, 'Renamed', 'post_purchase', FlowRecord::STATUS_ACTIVE, $current->source, array() );

		$gated = $page->gate_save( $current, $candidate );

		$this->assertSame( '', $gated['blocked'], 'a flow does not count against its own slot' );
		$this->assertTrue( $gated['record']->is_active() );
	}

	public function test_growth_allows_conditional_logic(): void {
		$flows = new InMemoryFlowRepository();
		$page  = $this->page( Plans::GROWTH, $flows );

		$current   = $this->flow( 1, FlowRecord::STATUS_DRAFT, array( $this->branching_step() ) );
		$candidate = $current->with_status( FlowRecord::STATUS_ACTIVE );

		$gated = $page->gate_save( $current, $candidate );

		$this->assertSame( '', $gated['blocked'] );
		$this->assertTrue( $gated['record']->is_active() );
	}
}
