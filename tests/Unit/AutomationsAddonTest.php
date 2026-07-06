<?php
/**
 * The Automations add-on's license + connection gating.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionRegistry;
use CartQuill\Automations\AutomationsAddon;
use CartQuill\Automations\SheetsAction;
use CartQuill\Automations\SlackAction;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Tests\Fake\StubSheetsClient;
use CartQuill\Tests\Fake\StubSlackClient;
use PHPUnit\Framework\TestCase;

final class AutomationsAddonTest extends TestCase {

	private function store_with( ?ConnectionRecord $slack ): InMemoryConnectionStore {
		$store = new InMemoryConnectionStore();
		if ( null !== $slack ) {
			$store->save( $slack );
		}
		return $store;
	}

	private function connected(): ConnectionRecord {
		return new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'https://hooks.slack.com/x' ) );
	}

	private function addon( InMemoryConnectionStore $store, ArrayLicense $license ): AutomationsAddon {
		return new AutomationsAddon( $store, $license, new StubSlackClient(), new StubSheetsClient() );
	}

	private function register( InMemoryConnectionStore $store, ArrayLicense $license ): ActionRegistry {
		$registry = new ActionRegistry();
		$this->addon( $store, $license )->register_actions( $registry, $license );
		return $registry;
	}

	public function test_registers_slack_when_licensed_and_connected(): void {
		$registry = $this->register( $this->store_with( $this->connected() ), new ArrayLicense( array( Plans::AUTOMATIONS ) ) );

		$this->assertTrue( $registry->has( SlackAction::TYPE ) );
	}

	public function test_does_not_register_without_a_license(): void {
		$registry = $this->register( $this->store_with( $this->connected() ), new ArrayLicense() );

		$this->assertFalse( $registry->has( SlackAction::TYPE ), 'unlicensed: the action is unavailable' );
	}

	public function test_does_not_register_without_a_connection(): void {
		$registry = $this->register( $this->store_with( null ), new ArrayLicense( array( Plans::AUTOMATIONS ) ) );

		$this->assertFalse( $registry->has( SlackAction::TYPE ), 'no connection: the action is unavailable' );
	}

	public function test_does_not_register_an_errored_connection(): void {
		$errored  = new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_ERROR, array( 'webhook_url' => 'https://hooks.slack.com/x' ) );
		$registry = $this->register( $this->store_with( $errored ), new ArrayLicense( array( Plans::AUTOMATIONS ) ) );

		$this->assertFalse( $registry->has( SlackAction::TYPE ), 'gated on connection status, not just credentials' );
	}

	public function test_registers_each_connected_service_independently(): void {
		$store = new InMemoryConnectionStore();
		$store->save( $this->connected() ); // slack connected
		$store->save(
			new ConnectionRecord( null, 'sheets', ConnectionRecord::STATUS_CONNECTED, array( 'service_account' => '{}', 'spreadsheet_id' => 'X' ) )
		);

		$registry = $this->register( $store, new ArrayLicense( array( Plans::AUTOMATIONS ) ) );

		$this->assertTrue( $registry->has( SlackAction::TYPE ) );
		$this->assertTrue( $registry->has( SheetsAction::TYPE ) );
	}

	public function test_sheets_not_registered_without_its_own_connection(): void {
		// Only Slack is connected; the Sheets action stays unavailable.
		$registry = $this->register( $this->store_with( $this->connected() ), new ArrayLicense( array( Plans::AUTOMATIONS ) ) );

		$this->assertTrue( $registry->has( SlackAction::TYPE ) );
		$this->assertFalse( $registry->has( SheetsAction::TYPE ) );
	}

	public function test_recipes_are_contributed_only_when_licensed(): void {
		$licensed   = $this->addon( $this->store_with( $this->connected() ), new ArrayLicense( array( Plans::AUTOMATIONS ) ) );
		$unlicensed = $this->addon( $this->store_with( $this->connected() ), new ArrayLicense() );

		$types = array_map( static fn ( $t ) => $t->type, $licensed->register_recipes( array() ) );
		$this->assertContains( 'order_alert', $types );

		$this->assertSame( array(), $unlicensed->register_recipes( array() ), 'no recipes without a license' );
	}
}
