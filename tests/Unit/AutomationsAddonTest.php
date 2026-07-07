<?php
/**
 * The Automations add-on's license + connection gating.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Action\ActionRegistry;
use CartQuill\Automations\AccountCreatedTrigger;
use CartQuill\Automations\AutomationsAddon;
use CartQuill\Automations\MailchimpAction;
use CartQuill\Automations\SheetsAction;
use CartQuill\Automations\SlackAction;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Automations\SmsAction;
use CartQuill\Tests\Fake\StubMailchimpClient;
use CartQuill\Tests\Fake\StubSheetsClient;
use CartQuill\Tests\Fake\StubSlackClient;
use CartQuill\Tests\Fake\StubTwilioClient;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AutomationsAddonTest extends TestCase {

	use MockeryPHPUnitIntegration;

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
		return new AutomationsAddon( $store, $license, new StubSlackClient(), new StubSheetsClient(), new StubMailchimpClient(), new StubTwilioClient() );
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

	public function test_registers_mailchimp_when_connected(): void {
		$store = new InMemoryConnectionStore();
		$store->save(
			new ConnectionRecord( null, 'mailchimp', ConnectionRecord::STATUS_CONNECTED, array( 'api_key' => 'k', 'server_prefix' => 'us1', 'audience_id' => 'a' ) )
		);

		$registry = $this->register( $store, new ArrayLicense( array( Plans::AUTOMATIONS ) ) );

		$this->assertTrue( $registry->has( MailchimpAction::TYPE ) );
	}

	public function test_registers_sms_when_connected(): void {
		$store = new InMemoryConnectionStore();
		$store->save(
			new ConnectionRecord( null, 'twilio', ConnectionRecord::STATUS_CONNECTED, array( 'account_sid' => 'AC', 'auth_token' => 't', 'from_number' => '+1' ) )
		);

		$registry = $this->register( $store, new ArrayLicense( array( Plans::AUTOMATIONS ) ) );

		$this->assertTrue( $registry->has( SmsAction::TYPE ) );
	}

	public function test_recipes_are_contributed_only_when_licensed(): void {
		$licensed   = $this->addon( $this->store_with( $this->connected() ), new ArrayLicense( array( Plans::AUTOMATIONS ) ) );
		$unlicensed = $this->addon( $this->store_with( $this->connected() ), new ArrayLicense() );

		$types = array_map( static fn ( $t ) => $t->type, $licensed->register_recipes( array() ) );
		$this->assertContains( 'order_alert', $types );
		$this->assertContains( AccountCreatedTrigger::TYPE, $types, 'the welcome recipe is contributed' );

		$this->assertSame( array(), $unlicensed->register_recipes( array() ), 'no recipes without a license' );
	}

	public function test_enhances_the_core_abandoned_cart_template_in_place(): void {
		$licensed = $this->addon( $this->store_with( $this->connected() ), new ArrayLicense( array( Plans::AUTOMATIONS ) ) );

		$out = $licensed->register_recipes( array( DefaultFlows::abandoned_cart() ) );

		$byType = array();
		foreach ( $out as $flow ) {
			$byType[ $flow->type ][] = $flow;
		}
		$this->assertCount( 1, $byType[ DefaultFlows::TYPE_ABANDONED_CART ], 'no duplicate abandoned-cart template' );
		$this->assertSame( 'Cart recovery + Mailchimp', $byType[ DefaultFlows::TYPE_ABANDONED_CART ][0]->name, 'the core template is replaced with the enhanced one' );
	}

	public function test_register_surfaces_wires_the_admin_page_and_triggers_without_error(): void {
		// register_surfaces() constructs the connections page + triggers; this guards
		// against a mismatched constructor (e.g. a missing injected client).
		Monkey\setUp();
		Functions\when( 'add_action' )->justReturn( true );

		$addon = $this->addon( $this->store_with( $this->connected() ), new ArrayLicense( array( Plans::AUTOMATIONS ) ) );
		$addon->register_surfaces();

		$this->assertTrue( true, 'the admin surfaces wired up without a fatal' );
		Monkey\tearDown();
	}
}
