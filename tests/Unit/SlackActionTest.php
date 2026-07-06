<?php
/**
 * The Slack action: posts to a configured webhook, degrades when it can't.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionContext;
use CartQuill\Automations\SlackAction;
use CartQuill\Automations\SlackResult;
use CartQuill\Flow\FlowStep;
use CartQuill\Flow\Renderer;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Tests\Fake\StubSlackClient;
use PHPUnit\Framework\TestCase;

final class SlackActionTest extends TestCase {

	private function connected_store(): InMemoryConnectionStore {
		$store = new InMemoryConnectionStore();
		$store->save(
			new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'https://hooks.slack.com/services/x' ) )
		);
		return $store;
	}

	private function context( FlowStep $step, array $ctx = array() ): ActionContext {
		return new ActionContext(
			step: $step,
			customer_email: 'buyer@example.com',
			flow_id: 1,
			step_index: 0,
			enrollment_id: 7,
			context: $ctx,
		);
	}

	private function step( array $config = array() ): FlowStep {
		return new FlowStep( 0, '', '', array(), SlackAction::TYPE, $config );
	}

	public function test_action_identity_is_internal(): void {
		$action = new SlackAction( $this->connected_store(), new StubSlackClient(), new Renderer() );

		$this->assertSame( 'slack_post', $action->type() );
		$this->assertSame( 'slack', $action->sender_key() );
		$this->assertFalse( $action->is_customer_facing(), 'Slack reaches no customer inbox' );
		$this->assertNull( $action->target( $this->context( $this->step() ) ), 'no suppression target' );
	}

	public function test_posts_the_rendered_message(): void {
		$client = new StubSlackClient();
		$action = new SlackAction( $this->connected_store(), $client, new Renderer() );

		$result = $action->execute(
			$this->context(
				$this->step( array( 'channel' => '#orders', 'text' => 'Order from {{ customer_email }}' ) )
			)
		);

		$this->assertTrue( $result->is_accepted() );
		$this->assertSame( 1, $client->count() );
		$this->assertSame( '#orders', $client->last()['channel'] );
		$this->assertSame( 'Order from buyer@example.com', $client->last()['text'], 'the template is rendered' );
	}

	public function test_records_the_transport_ref_when_one_is_returned(): void {
		// A transport that returns a message reference (e.g. the Slack Web API's
		// ts) has it recorded as the external id.
		$client = new StubSlackClient();
		$client->will_return( SlackResult::ok( 'ts-123' ) );
		$action = new SlackAction( $this->connected_store(), $client, new Renderer() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertTrue( $result->is_accepted() );
		$this->assertSame( 'ts-123', $result->external_id );
	}

	public function test_incoming_webhook_with_no_ref_accepts_with_a_null_external_id(): void {
		// The production HttpSlackClient posts to an incoming webhook, which
		// acknowledges with "ok" and no ts — the send is accepted with no id.
		$client = new StubSlackClient();
		$client->will_return( SlackResult::ok() );
		$action = new SlackAction( $this->connected_store(), $client, new Renderer() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertTrue( $result->is_accepted() );
		$this->assertNull( $result->external_id );
	}

	public function test_missing_connection_fails_without_posting(): void {
		$action = new SlackAction( new InMemoryConnectionStore(), $client = new StubSlackClient(), new Renderer() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 0, $client->count(), 'nothing is posted without a connection' );
	}

	public function test_a_failed_post_becomes_a_failed_result(): void {
		$client = new StubSlackClient();
		$client->will_return( SlackResult::failed( 'Slack responded 404' ) );
		$action = new SlackAction( $this->connected_store(), $client, new Renderer() );

		$result = $action->execute( $this->context( $this->step() ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 'Slack responded 404', $result->error );
	}
}
