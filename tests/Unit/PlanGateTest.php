<?php
/**
 * The plan gate enforced at flow activation: the active-workflow cap and the
 * conditional-logic entitlement. Both read the plan's numeric limits, so the
 * `cartquill_plan_limits` filter override reaches them through the license.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\PlanGate;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Flow\FlowStep;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class PlanGateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function flow( ?int $id, string $status, array $steps = array() ): FlowRecord {
		return new FlowRecord( $id, 'Flow ' . (string) $id, 'post_purchase', $status, FlowRecord::SOURCE_TEMPLATE, $steps );
	}

	private function gate( string $tier, InMemoryFlowRepository $flows ): PlanGate {
		return new PlanGate( new ArrayLicense( array( $tier ), Plans::entitlements( $tier ) ), $flows );
	}

	private function seed_active( InMemoryFlowRepository $flows, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$flows->save( $this->flow( null, FlowRecord::STATUS_ACTIVE ) );
		}
	}

	public function test_starter_blocks_activating_a_sixth_workflow(): void {
		$flows = new InMemoryFlowRepository();
		$this->seed_active( $flows, 5 ); // Starter cap is 5.
		$gate = $this->gate( Plans::STARTER, $flows );

		$sixth = $this->flow( 6, FlowRecord::STATUS_ACTIVE );
		$this->assertSame( PlanGate::REASON_WORKFLOW_CAP, $gate->activation_error( $sixth ) );
	}

	public function test_starter_allows_the_fifth_workflow(): void {
		$flows = new InMemoryFlowRepository();
		$this->seed_active( $flows, 4 );
		$gate = $this->gate( Plans::STARTER, $flows );

		$fifth = $this->flow( 5, FlowRecord::STATUS_ACTIVE );
		$this->assertSame( '', $gate->activation_error( $fifth ), 'the fifth is within the cap' );
	}

	public function test_re_saving_an_already_active_flow_does_not_count_itself(): void {
		$flows = new InMemoryFlowRepository();
		$this->seed_active( $flows, 5 ); // ids 1..5, all active, Starter cap 5.
		$gate = $this->gate( Plans::STARTER, $flows );

		// Re-activating flow #3 (already active) must not be blocked by its own row.
		$this->assertSame( '', $gate->activation_error( $this->flow( 3, FlowRecord::STATUS_ACTIVE ) ) );
	}

	public function test_growth_unlocks_unlimited_workflows(): void {
		$flows = new InMemoryFlowRepository();
		$this->seed_active( $flows, 20 );
		$gate = $this->gate( Plans::GROWTH, $flows );

		$another = $this->flow( 21, FlowRecord::STATUS_ACTIVE );
		$this->assertSame( '', $gate->activation_error( $another ), 'growth has no workflow cap' );
	}

	public function test_conditional_logic_is_gated_on_starter_and_unlocked_on_growth(): void {
		$branching = new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'cart_value_gt', 'value' => 50 ) ) );

		$starter_flows = new InMemoryFlowRepository();
		$starter        = $this->flow( 1, FlowRecord::STATUS_ACTIVE, array( $branching ) );
		$this->assertSame(
			PlanGate::REASON_CONDITIONAL_LOGIC,
			$this->gate( Plans::STARTER, $starter_flows )->activation_error( $starter )
		);

		$growth_flows = new InMemoryFlowRepository();
		$growth       = $this->flow( 1, FlowRecord::STATUS_ACTIVE, array( $branching ) );
		$this->assertSame( '', $this->gate( Plans::GROWTH, $growth_flows )->activation_error( $growth ) );
	}

	public function test_exit_and_delay_are_not_conditional_logic_so_starter_can_run_core_recipes(): void {
		// The abandoned-cart shape: a delay plus the exit-on-conversion guard is a
		// core drip, not the paid conditional-logic feature — Starter may activate it.
		$core = $this->flow(
			1,
			FlowRecord::STATUS_ACTIVE,
			array( new FlowStep( 3600, 'Come back', 'body', array( array( 'type' => 'exit_if_ordered' ) ) ) )
		);

		$this->assertSame( '', $this->gate( Plans::STARTER, new InMemoryFlowRepository() )->activation_error( $core ) );
	}

	public function test_draft_and_paused_flows_are_never_gated(): void {
		$flows = new InMemoryFlowRepository();
		$this->seed_active( $flows, 5 );
		$gate = $this->gate( Plans::STARTER, $flows );

		$branching = array( new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'cart_value_gt', 'value' => 50 ) ) ) );
		$this->assertSame( '', $gate->activation_error( $this->flow( 9, FlowRecord::STATUS_DRAFT, $branching ) ) );
		$this->assertSame( '', $gate->activation_error( $this->flow( 9, FlowRecord::STATUS_PAUSED, $branching ) ) );
	}

	public function test_enforce_passes_through_an_allowed_flow_unchanged(): void {
		$gate  = $this->gate( Plans::GROWTH, new InMemoryFlowRepository() );
		$flow  = $this->flow( 1, FlowRecord::STATUS_ACTIVE, array( new FlowStep( 0, 'Hi', 'b', array( array( 'type' => 'cart_value_gt', 'value' => 50 ) ) ) ) );

		$result = $gate->enforce( $flow, $flow );

		$this->assertSame( '', $result['blocked'] );
		$this->assertSame( FlowRecord::STATUS_ACTIVE, $result['record']->status );
	}

	public function test_enforce_reverts_a_blocked_activation_to_a_safe_status(): void {
		$branching = array( new FlowStep( 0, 'Hi', 'b', array( array( 'type' => 'cart_value_gt', 'value' => 50 ) ) ) );
		$gate      = $this->gate( Plans::STARTER, new InMemoryFlowRepository() );

		// A previously-active flow being re-saved active but now blocked → paused.
		$current   = $this->flow( 1, FlowRecord::STATUS_ACTIVE );
		$candidate = $this->flow( 1, FlowRecord::STATUS_ACTIVE, $branching );
		$reverted  = $gate->enforce( $candidate, $current );
		$this->assertSame( PlanGate::REASON_CONDITIONAL_LOGIC, $reverted['blocked'] );
		$this->assertSame( FlowRecord::STATUS_PAUSED, $reverted['record']->status );

		// A brand-new flow (no current) that can't activate → saved as a draft.
		$created = $gate->enforce( $this->flow( null, FlowRecord::STATUS_ACTIVE, $branching ), null );
		$this->assertSame( PlanGate::REASON_CONDITIONAL_LOGIC, $created['blocked'] );
		$this->assertSame( FlowRecord::STATUS_DRAFT, $created['record']->status );
	}

	public function test_conditional_logic_is_checked_before_the_workflow_cap(): void {
		// A flow that trips both gates reports the conditional-logic reason first.
		$flows = new InMemoryFlowRepository();
		$this->seed_active( $flows, 5 );
		$gate = $this->gate( Plans::STARTER, $flows );

		$flow = $this->flow(
			6,
			FlowRecord::STATUS_ACTIVE,
			array( new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'first_time_customer' ) ) ) )
		);
		$this->assertSame( PlanGate::REASON_CONDITIONAL_LOGIC, $gate->activation_error( $flow ) );
	}

	public function test_presave_filter_reverts_a_blocked_activation_and_explains_why(): void {
		$flows = new InMemoryFlowRepository();
		$this->seed_active( $flows, 5 ); // Starter cap is 5.
		$gate = $this->gate( Plans::STARTER, $flows );

		$candidate = $this->flow( null, FlowRecord::STATUS_ACTIVE );
		$pending   = array(
			'record'  => $candidate,
			'blocked' => '',
		);

		$result = $gate->presave_filter( $pending, $candidate, null );

		$this->assertSame( FlowRecord::STATUS_DRAFT, $result['record']->status, 'kept out of active, edits preserved' );
		$this->assertNotSame( '', $result['blocked'], 'the builder is told why' );
	}

	public function test_presave_filter_passes_an_allowed_save_through_untouched(): void {
		$flows = new InMemoryFlowRepository();
		$gate  = $this->gate( Plans::GROWTH, $flows );

		$candidate = $this->flow( null, FlowRecord::STATUS_ACTIVE );
		$result    = $gate->presave_filter(
			array(
				'record'  => $candidate,
				'blocked' => '',
			),
			$candidate,
			null
		);

		$this->assertSame( FlowRecord::STATUS_ACTIVE, $result['record']->status );
		$this->assertSame( '', $result['blocked'] );
	}
}
