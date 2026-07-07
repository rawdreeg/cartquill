<?php
/**
 * The Mailchimp action: an audience sync, not a sender.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionContext;
use CartQuill\Automations\MailchimpAction;
use CartQuill\Automations\MailchimpResult;
use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Tests\Fake\StubMailchimpClient;
use PHPUnit\Framework\TestCase;

final class MailchimpActionTest extends TestCase {

	private function connected_store(): InMemoryConnectionStore {
		$store = new InMemoryConnectionStore();
		$store->save(
			new ConnectionRecord(
				null,
				'mailchimp',
				ConnectionRecord::STATUS_CONNECTED,
				array(
					'api_key'       => 'secret-us21',
					'server_prefix' => 'us21',
					'audience_id'   => 'aud-1',
				)
			)
		);
		return $store;
	}

	private function context( FlowStep $step ): ActionContext {
		return new ActionContext(
			step: $step,
			customer_email: 'buyer@example.com',
			flow_id: 1,
			step_index: 0,
			enrollment_id: 7,
		);
	}

	private function step( array $config = array() ): FlowStep {
		return new FlowStep( 0, '', '', array(), MailchimpAction::TYPE, $config );
	}

	public function test_action_identity_is_internal(): void {
		$action = new MailchimpAction( $this->connected_store(), new StubMailchimpClient() );

		$this->assertSame( 'mailchimp_sync', $action->type() );
		$this->assertSame( 'mailchimp', $action->sender_key() );
		$this->assertFalse( $action->is_customer_facing(), 'an audience sync reaches no customer inbox' );
		$this->assertNull( $action->target( $this->context( $this->step() ) ) );
	}

	public function test_upserts_and_tags_the_member(): void {
		$client = new StubMailchimpClient();
		$action = new MailchimpAction( $this->connected_store(), $client );

		$result = $action->execute( $this->context( $this->step( array( 'tag' => 'welcome' ) ) ) );

		$this->assertTrue( $result->is_accepted() );
		$this->assertSame( 'member-abc123', $result->external_id );
		$this->assertSame( 1, $client->count() );
		$this->assertSame( 'buyer@example.com', $client->last()['email'] );
		$this->assertSame( 'welcome', $client->last()['tag'] );
		$this->assertSame( 'us21', $client->last()['credentials']['server_prefix'] );
	}

	public function test_missing_connection_fails_without_syncing(): void {
		$action = new MailchimpAction( new InMemoryConnectionStore(), $client = new StubMailchimpClient() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 0, $client->count() );
	}

	public function test_incomplete_connection_fails(): void {
		$store = new InMemoryConnectionStore();
		$store->save( new ConnectionRecord( null, 'mailchimp', ConnectionRecord::STATUS_CONNECTED, array( 'api_key' => 'x' ) ) ); // no audience/prefix
		$action = new MailchimpAction( $store, $client = new StubMailchimpClient() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 0, $client->count() );
	}

	public function test_a_failed_sync_becomes_a_failed_result(): void {
		$client = new StubMailchimpClient();
		$client->will_return( MailchimpResult::failed( 'Mailchimp upsert responded 401.' ) );
		$action = new MailchimpAction( $this->connected_store(), $client );

		$result = $action->execute( $this->context( $this->step( array( 'tag' => 'welcome' ) ) ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 'Mailchimp upsert responded 401.', $result->error );
	}
}
