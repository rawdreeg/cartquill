<?php
/**
 * The SMS action: texts the customer via Twilio, customer-facing.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Action\ActionContext;
use CartQuill\Automations\SmsAction;
use CartQuill\Automations\SmsResult;
use CartQuill\Flow\FlowStep;
use CartQuill\Flow\Renderer;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Tests\Fake\StubTwilioClient;
use PHPUnit\Framework\TestCase;

final class SmsActionTest extends TestCase {

	private function connected_store(): InMemoryConnectionStore {
		$store = new InMemoryConnectionStore();
		$store->save(
			new ConnectionRecord(
				null,
				'twilio',
				ConnectionRecord::STATUS_CONNECTED,
				array( 'account_sid' => 'AC1', 'auth_token' => 'tok', 'from_number' => '+15550000000' )
			)
		);
		return $store;
	}

	private function context( array $ctx = array( 'phone' => '+1 (555) 111-2222' ) ): ActionContext {
		return new ActionContext(
			step: new FlowStep( 0, '', '', array(), SmsAction::TYPE, array( 'text' => 'Shipped! {{ tracking_url }}' ) ),
			customer_email: 'buyer@example.com',
			flow_id: 1,
			step_index: 0,
			enrollment_id: 7,
			context: $ctx,
		);
	}

	public function test_action_is_customer_facing_and_targets_the_normalized_phone(): void {
		$action = new SmsAction( $this->connected_store(), new StubTwilioClient(), new Renderer() );

		$this->assertSame( 'sms_send', $action->type() );
		$this->assertSame( 'twilio', $action->sender_key() );
		$this->assertTrue( $action->is_customer_facing(), 'SMS reaches the customer, so suppression applies' );
		$this->assertSame( '+15551112222', $action->target( $this->context() ), 'phone is normalized for the suppression key' );
		$this->assertNull( $action->target( $this->context( array() ) ), 'no phone -> no target' );
	}

	public function test_sends_the_rendered_text_and_records_the_sid(): void {
		$client = new StubTwilioClient();
		$action = new SmsAction( $this->connected_store(), $client, new Renderer() );

		$result = $action->execute( $this->context( array( 'phone' => '+15551112222', 'tracking_url' => 'https://track/1' ) ) );

		$this->assertTrue( $result->is_accepted() );
		$this->assertSame( 'SM1234567890', $result->external_id, 'the Twilio message SID is recorded' );
		$this->assertSame( 1, $client->count() );
		$this->assertSame( '+15551112222', $client->last()['to'] );
		$this->assertSame( 'Shipped! https://track/1', $client->last()['body'], 'body rendered from context' );
	}

	public function test_no_phone_fails_without_texting(): void {
		$action = new SmsAction( $this->connected_store(), $client = new StubTwilioClient(), new Renderer() );

		$result = $action->execute( $this->context( array() ) );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 0, $client->count() );
	}

	public function test_a_failed_send_becomes_a_failed_result(): void {
		$client = new StubTwilioClient();
		$client->will_return( SmsResult::failed( 'Twilio responded 400.' ) );
		$action = new SmsAction( $this->connected_store(), $client, new Renderer() );

		$result = $action->execute( $this->context() );

		$this->assertFalse( $result->is_accepted() );
		$this->assertSame( 'Twilio responded 400.', $result->error );
	}
}
