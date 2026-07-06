<?php
/**
 * The Sheets action: appends a rendered row, degrades when it can't.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionContext;
use CartQuill\Automations\SheetsAction;
use CartQuill\Automations\SheetsResult;
use CartQuill\Flow\FlowStep;
use CartQuill\Flow\Renderer;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Tests\Fake\StubSheetsClient;
use PHPUnit\Framework\TestCase;

final class SheetsActionTest extends TestCase {

	private const SA_JSON = '{"client_email":"bot@proj.iam.gserviceaccount.com","private_key":"KEY"}';

	private function connected_store(): InMemoryConnectionStore {
		$store = new InMemoryConnectionStore();
		$store->save(
			new ConnectionRecord(
				null,
				'sheets',
				ConnectionRecord::STATUS_CONNECTED,
				array(
					'service_account' => self::SA_JSON,
					'spreadsheet_id'  => 'SHEET-1',
					'range'           => 'Sheet1!A:C',
				)
			)
		);
		return $store;
	}

	private function context( FlowStep $step, array $ctx = array() ): ActionContext {
		return new ActionContext(
			step: $step,
			customer_email: 'buyer@example.com',
			flow_id: 1,
			step_index: 1,
			enrollment_id: 7,
			context: $ctx,
		);
	}

	private function step( array $config = array() ): FlowStep {
		return new FlowStep( 0, '', '', array(), SheetsAction::TYPE, $config );
	}

	public function test_action_identity_is_internal(): void {
		$action = new SheetsAction( $this->connected_store(), new StubSheetsClient(), new Renderer() );

		$this->assertSame( 'sheets_append', $action->type() );
		$this->assertSame( 'google_sheets', $action->sender_key() );
		$this->assertFalse( $action->is_customer_facing() );
		$this->assertNull( $action->target( $this->context( $this->step() ) ) );
	}

	public function test_appends_a_rendered_row_to_the_configured_sheet(): void {
		$client = new StubSheetsClient();
		$action = new SheetsAction( $this->connected_store(), $client, new Renderer() );

		$result = $action->execute(
			$this->context(
				$this->step( array( 'columns' => array( '{{ order_id }}', '{{ customer_email }}', '{{ order_total }}' ) ) ),
				array( 'order_id' => 123, 'order_total' => 49.5 )
			)
		);

		$this->assertTrue( $result->is_accepted() );
		$this->assertSame( 'Sheet1!A5:C5', $result->external_id, 'the updated range is recorded' );
		$this->assertSame( 1, $client->count() );
		$this->assertSame( 'SHEET-1', $client->last()['spreadsheet_id'] );
		$this->assertSame( 'Sheet1!A:C', $client->last()['range'] );
		$this->assertSame( array( '123', 'buyer@example.com', '49.5' ), $client->last()['row'], 'columns rendered from context' );
		$this->assertSame( 'bot@proj.iam.gserviceaccount.com', $client->last()['service_account']['client_email'], 'SA JSON decoded and passed through' );
	}

	public function test_missing_connection_fails_without_appending(): void {
		$action = new SheetsAction( new InMemoryConnectionStore(), $client = new StubSheetsClient(), new Renderer() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 0, $client->count() );
	}

	public function test_incomplete_connection_fails(): void {
		$store = new InMemoryConnectionStore();
		$store->save( new ConnectionRecord( null, 'sheets', ConnectionRecord::STATUS_CONNECTED, array( 'service_account' => self::SA_JSON ) ) ); // no spreadsheet_id
		$action = new SheetsAction( $store, $client = new StubSheetsClient(), new Renderer() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 0, $client->count() );
	}

	public function test_a_failed_append_becomes_a_failed_result(): void {
		$client = new StubSheetsClient();
		$client->will_return( SheetsResult::failed( 'Sheets API responded 403.' ) );
		$action = new SheetsAction( $this->connected_store(), $client, new Renderer() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 'Sheets API responded 403.', $result->error );
	}
}
