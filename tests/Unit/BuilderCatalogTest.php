<?php
/**
 * The builder catalog: the machine-readable metadata a drag-and-drop builder
 * renders from — triggers, actions, and conditions with their editable fields and
 * an availability flag driven by the held plan and connection status.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\EmailAction;
use CartQuill\Builder\BuilderCatalog;
use CartQuill\Builder\CoreActionDescriptors;
use CartQuill\Builder\CoreTriggers;
use CartQuill\Builder\DescribesConfig;
use CartQuill\Builder\TriggerDescriptor;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\ConnectionStore;
use CartQuill\Persistence\InMemoryConnectionStore;
use PHPUnit\Framework\TestCase;

final class BuilderCatalogTest extends TestCase {

	/** No plan held, nothing connected — the free-tier baseline. */
	private function free_catalog(): BuilderCatalog {
		return new BuilderCatalog( new ArrayLicense(), new InMemoryConnectionStore(), CoreActionDescriptors::all(), CoreTriggers::all() );
	}

	private function catalog( ArrayLicense $license, ConnectionStore $connections, ?array $triggers = null ): BuilderCatalog {
		return new BuilderCatalog( $license, $connections, CoreActionDescriptors::all(), $triggers ?? CoreTriggers::all() );
	}

	/** Core triggers plus a paid one, as the Automations add-on would contribute. */
	private function triggers_with_paid(): array {
		return array_merge(
			CoreTriggers::all(),
			array( new TriggerDescriptor( 'order_alert', 'New paid order', 'An order was paid.', array( 'order_id', 'order_total' ), Plans::AUTOMATIONS ) )
		);
	}

	private function tier( string $tier ): ArrayLicense {
		return new ArrayLicense( array( $tier ), Plans::entitlements( $tier ) );
	}

	private function connected( string $service ): InMemoryConnectionStore {
		$store = new InMemoryConnectionStore();
		$store->save( new ConnectionRecord( null, $service, ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'https://example.test/x' ) ) );
		return $store;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return array<string, array<string, mixed>> keyed by type
	 */
	private function by_type( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['type'] ] = $row;
		}
		return $out;
	}

	public function test_exposes_triggers_actions_and_conditions(): void {
		$catalog = $this->free_catalog()->to_array();

		$this->assertArrayHasKey( 'triggers', $catalog );
		$this->assertArrayHasKey( 'actions', $catalog );
		$this->assertArrayHasKey( 'conditions', $catalog );
		$this->assertNotEmpty( $catalog['triggers'] );
		$this->assertNotEmpty( $catalog['actions'] );
		$this->assertNotEmpty( $catalog['conditions'] );
	}

	public function test_core_triggers_carry_their_context_keys_and_customer_email(): void {
		$triggers = $this->by_type( $this->free_catalog()->triggers() );

		$this->assertArrayHasKey( 'abandoned_cart', $triggers );
		$this->assertContains( 'cart_value', $triggers['abandoned_cart']['context_keys'] );
		$this->assertContains( 'customer_email', $triggers['abandoned_cart']['context_keys'], 'every trigger offers the customer email' );

		// The paid triggers live with the add-on, not in the core catalog.
		$this->assertArrayNotHasKey( 'order_alert', $triggers );
	}

	public function test_email_action_is_available_on_every_tier(): void {
		$actions = $this->by_type( $this->free_catalog()->actions() );

		$this->assertTrue( $actions['email']['available'] );
		$this->assertSame( '', $actions['email']['lock_reason'] );
		$this->assertTrue( $actions['email']['customer_facing'] );
	}

	public function test_email_descriptor_matches_the_action_and_cannot_drift(): void {
		$actions = $this->by_type( $this->free_catalog()->actions() );

		$this->assertSame( EmailAction::config_fields(), $actions['email']['fields'] );
		$keys = array_column( $actions['email']['fields'], 'key' );
		$this->assertContains( 'subject', $keys );
		$this->assertContains( 'body', $keys );
	}

	public function test_email_action_still_implements_the_action_interface(): void {
		// ActionInterface is unchanged; DescribesConfig is an additive opt-in.
		$interfaces = class_implements( EmailAction::class );
		$this->assertContains( \CartQuill\Action\ActionInterface::class, $interfaces );
		$this->assertContains( DescribesConfig::class, $interfaces );
	}

	public function test_paid_actions_are_locked_without_the_automations_plan(): void {
		$actions = $this->by_type( $this->free_catalog()->actions() );

		$this->assertFalse( $actions['slack_post']['available'] );
		$this->assertSame( BuilderCatalog::LOCK_PLAN, $actions['slack_post']['lock_reason'] );
	}

	public function test_paid_action_is_locked_when_licensed_but_not_connected(): void {
		// Growth grants the automations capability, but no Slack connection exists.
		$actions = $this->by_type( $this->catalog( $this->tier( Plans::GROWTH ), new InMemoryConnectionStore() )->actions() );

		$this->assertFalse( $actions['slack_post']['available'] );
		$this->assertSame( BuilderCatalog::LOCK_CONNECTION, $actions['slack_post']['lock_reason'] );
	}

	public function test_paid_action_is_available_when_licensed_and_connected(): void {
		$actions = $this->by_type( $this->catalog( $this->tier( Plans::GROWTH ), $this->connected( 'slack' ) )->actions() );

		$this->assertTrue( $actions['slack_post']['available'] );
		$this->assertSame( '', $actions['slack_post']['lock_reason'] );
	}

	public function test_contributed_paid_triggers_track_the_plan(): void {
		// A paid trigger (as the add-on contributes it) is locked without the plan.
		$free = $this->by_type( $this->catalog( new ArrayLicense(), new InMemoryConnectionStore(), $this->triggers_with_paid() )->triggers() );
		$this->assertFalse( $free['order_alert']['available'], 'no automations plan' );
		$this->assertSame( BuilderCatalog::LOCK_PLAN, $free['order_alert']['lock_reason'] );

		// Available on a plan that grants automations; core triggers never gate.
		$paid = $this->by_type( $this->catalog( $this->tier( Plans::GROWTH ), new InMemoryConnectionStore(), $this->triggers_with_paid() )->triggers() );
		$this->assertTrue( $paid['order_alert']['available'] );
		$this->assertTrue( $paid['abandoned_cart']['available'] );
	}

	public function test_conditions_flag_which_are_conditional_logic(): void {
		$conditions = $this->by_type( $this->free_catalog()->conditions() );

		$this->assertFalse( $conditions['exit_if_ordered']['conditional_logic'], 'exit guard is a core drip primitive' );
		$this->assertTrue( $conditions['cart_value_gt']['conditional_logic'] );
		$this->assertTrue( $conditions['first_time_customer']['conditional_logic'] );
	}

	public function test_conditional_logic_gates_are_locked_without_the_entitlement(): void {
		// Starter holds the automations plan but not the conditional-logic entitlement.
		$starter = $this->by_type( $this->catalog( $this->tier( Plans::STARTER ), new InMemoryConnectionStore() )->conditions() );
		$this->assertFalse( $starter['cart_value_gt']['available'] );
		$this->assertSame( BuilderCatalog::LOCK_CONDITIONAL_LOGIC, $starter['cart_value_gt']['lock_reason'] );

		// The exit-on-conversion guard is available on every tier.
		$this->assertTrue( $starter['exit_if_ordered']['available'] );

		// Growth unlocks conditional logic.
		$growth = $this->by_type( $this->catalog( $this->tier( Plans::GROWTH ), new InMemoryConnectionStore() )->conditions() );
		$this->assertTrue( $growth['cart_value_gt']['available'] );
		$this->assertSame( '', $growth['cart_value_gt']['lock_reason'] );
	}

	public function test_condition_params_describe_their_editable_fields(): void {
		$conditions = $this->by_type( $this->free_catalog()->conditions() );

		$value_param = array_column( $conditions['cart_value_gt']['params'], 'key' );
		$this->assertContains( 'value', $value_param );
	}
}
