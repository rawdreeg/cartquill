<?php
/**
 * Validating an incoming builder payload against the catalog, and turning a valid
 * payload into a FlowRecord.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Builder\BuilderCatalog;
use CartQuill\Builder\CoreActionDescriptors;
use CartQuill\Builder\CoreTriggers;
use CartQuill\Builder\FlowValidator;
use CartQuill\Builder\TriggerDescriptor;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\ConnectionRecord;
use CartQuill\Persistence\ConnectionStore;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class FlowValidatorTest extends TestCase {

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

	/** A free-tier catalog: only the email action is available. */
	private function free_validator(): FlowValidator {
		return new FlowValidator( new BuilderCatalog( new ArrayLicense(), new InMemoryConnectionStore(), CoreActionDescriptors::all(), CoreTriggers::all() ) );
	}

	/** A licensed + Slack-connected catalog whose triggers include the paid ones. */
	private function paid_validator(): FlowValidator {
		$connections = new InMemoryConnectionStore();
		$connections->save( new ConnectionRecord( null, 'slack', ConnectionRecord::STATUS_CONNECTED, array( 'webhook_url' => 'https://x.test' ) ) );
		$triggers = array_merge(
			CoreTriggers::all(),
			array( new TriggerDescriptor( 'order_alert', 'New paid order', '', array( 'order_total' ), Plans::AUTOMATIONS ) )
		);
		$license = new ArrayLicense( array( Plans::GROWTH ), Plans::entitlements( Plans::GROWTH ) );
		return new FlowValidator( new BuilderCatalog( $license, $connections, CoreActionDescriptors::all(), $triggers ) );
	}

	/** @return array<string, string> field => message */
	private function errors_by_field( array $errors ): array {
		$out = array();
		foreach ( $errors as $error ) {
			$out[ $error['field'] ] = $error['message'];
		}
		return $out;
	}

	private function email_step( string $subject = 'Hi', string $body = 'Body' ): array {
		return array( 'delay' => 0, 'action' => 'email', 'config' => array( 'subject' => $subject, 'body' => $body ), 'conditions' => array() );
	}

	public function test_a_valid_email_flow_has_no_errors(): void {
		$payload = array(
			'name'   => 'Welcome',
			'type'   => 'welcome',
			'status' => 'active',
			'steps'  => array( $this->email_step() ),
		);

		$this->assertSame( array(), $this->free_validator()->validate( $payload ) );
	}

	public function test_to_record_round_trips_email_and_typed_steps(): void {
		$payload = array(
			'name'   => 'Order alert',
			'type'   => 'order_alert',
			'status' => 'draft',
			'steps'  => array(
				$this->email_step( 'Thanks!', '<p>Body</p>' ),
				array( 'delay' => 3600, 'action' => 'slack_post', 'config' => array( 'channel' => '#orders', 'text' => 'Hi' ), 'conditions' => array( array( 'type' => 'exit_if_ordered' ) ) ),
			),
		);

		$record = $this->paid_validator()->to_record( $payload, 5, FlowRecord::SOURCE_BUILDER );

		$this->assertSame( 5, $record->id );
		$this->assertSame( 'order_alert', $record->type );
		$this->assertSame( FlowRecord::SOURCE_BUILDER, $record->source );
		// Email step: config folded back to the top-level subject/body FlowStep reads.
		$this->assertSame( 'email', $record->steps[0]->action );
		$this->assertSame( 'Thanks!', $record->steps[0]->subject );
		$this->assertSame( '<p>Body</p>', $record->steps[0]->body );
		// Typed step: action + config preserved.
		$this->assertSame( 'slack_post', $record->steps[1]->action );
		$this->assertSame( array( 'channel' => '#orders', 'text' => 'Hi' ), $record->steps[1]->config );
		$this->assertSame( 3600, $record->steps[1]->delay );
	}

	public function test_name_is_required(): void {
		$errors = $this->errors_by_field( $this->free_validator()->validate( array( 'name' => '  ', 'type' => 'welcome', 'status' => 'draft', 'steps' => array() ) ) );
		$this->assertArrayHasKey( 'name', $errors );
	}

	public function test_unknown_trigger_type_is_rejected(): void {
		$errors = $this->errors_by_field( $this->free_validator()->validate( array( 'name' => 'X', 'type' => 'not_a_trigger', 'status' => 'draft', 'steps' => array() ) ) );
		$this->assertArrayHasKey( 'type', $errors );
	}

	public function test_invalid_status_is_rejected(): void {
		$errors = $this->errors_by_field( $this->free_validator()->validate( array( 'name' => 'X', 'type' => 'welcome', 'status' => 'sideways', 'steps' => array() ) ) );
		$this->assertArrayHasKey( 'status', $errors );
	}

	public function test_a_locked_action_is_rejected(): void {
		// Slack is locked on the free tier.
		$payload = array( 'name' => 'X', 'type' => 'welcome', 'status' => 'draft', 'steps' => array(
			array( 'delay' => 0, 'action' => 'slack_post', 'config' => array( 'channel' => '#o' ), 'conditions' => array() ),
		) );
		$errors  = $this->errors_by_field( $this->free_validator()->validate( $payload ) );
		$this->assertArrayHasKey( 'steps.0.action', $errors );
	}

	public function test_an_unknown_action_is_rejected(): void {
		$payload = array( 'name' => 'X', 'type' => 'welcome', 'status' => 'draft', 'steps' => array(
			array( 'delay' => 0, 'action' => 'teleport', 'config' => array(), 'conditions' => array() ),
		) );
		$this->assertArrayHasKey( 'steps.0.action', $this->errors_by_field( $this->free_validator()->validate( $payload ) ) );
	}

	public function test_unknown_config_field_and_missing_required_field_are_rejected(): void {
		$payload = array( 'name' => 'X', 'type' => 'welcome', 'status' => 'draft', 'steps' => array(
			array( 'delay' => 0, 'action' => 'email', 'config' => array( 'subject' => '', 'bogus' => 'x' ), 'conditions' => array() ),
		) );
		$errors  = $this->errors_by_field( $this->free_validator()->validate( $payload ) );
		$this->assertArrayHasKey( 'steps.0.config.bogus', $errors, 'unknown setting' );
		$this->assertArrayHasKey( 'steps.0.config.subject', $errors, 'required subject empty' );
	}

	public function test_negative_delay_is_rejected(): void {
		$payload = array( 'name' => 'X', 'type' => 'welcome', 'status' => 'draft', 'steps' => array(
			array( 'delay' => -5, 'action' => 'email', 'config' => array( 'subject' => 'a', 'body' => 'b' ), 'conditions' => array() ),
		) );
		$this->assertArrayHasKey( 'steps.0.delay', $this->errors_by_field( $this->free_validator()->validate( $payload ) ) );
	}

	public function test_a_select_value_outside_its_options_is_rejected(): void {
		$payload = array( 'name' => 'X', 'type' => 'welcome', 'status' => 'draft', 'steps' => array(
			array( 'delay' => 0, 'action' => 'email', 'config' => array( 'subject' => 'a', 'body' => 'b' ), 'conditions' => array(
				array( 'type' => 'exit_if_ordered', 'action' => 'garbage' ),
			) ),
		) );
		$this->assertArrayHasKey( 'steps.0.conditions.0.action', $this->errors_by_field( $this->free_validator()->validate( $payload ) ) );
	}

	public function test_a_valid_select_value_passes(): void {
		$payload = array( 'name' => 'X', 'type' => 'welcome', 'status' => 'draft', 'steps' => array(
			array( 'delay' => 0, 'action' => 'email', 'config' => array( 'subject' => 'a', 'body' => 'b' ), 'conditions' => array(
				array( 'type' => 'exit_if_ordered', 'action' => 'skip' ),
			) ),
		) );
		$this->assertSame( array(), $this->free_validator()->validate( $payload ) );
	}

	public function test_unknown_condition_and_bad_param_are_rejected(): void {
		$payload = array( 'name' => 'X', 'type' => 'welcome', 'status' => 'draft', 'steps' => array(
			array( 'delay' => 0, 'action' => 'email', 'config' => array( 'subject' => 'a', 'body' => 'b' ), 'conditions' => array(
				array( 'type' => 'made_up' ),
				array( 'type' => 'cart_value_gt', 'value' => 'lots' ),
			) ),
		) );
		$errors  = $this->errors_by_field( $this->free_validator()->validate( $payload ) );
		$this->assertArrayHasKey( 'steps.0.conditions.0.type', $errors );
		$this->assertArrayHasKey( 'steps.0.conditions.1.value', $errors );
	}
}
