<?php
/**
 * The builder's REST read API: the catalog + stored flows, gated on capability.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use CartQuill\Builder\ActionDescriptor;
use CartQuill\Builder\BuilderCatalog;
use CartQuill\Builder\CoreActionDescriptors;
use CartQuill\Builder\CoreTriggers;
use CartQuill\Builder\FlowSerializer;
use CartQuill\Builder\FlowValidator;
use CartQuill\Flow\FlowStep;
use CartQuill\Builder\OpenAvailability;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryConnectionStore;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Rest\FlowBuilderController;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class FlowBuilderControllerTest extends TestCase {

	/** A capability no shipped descriptor requires; see BuilderCatalogTest. */
	private const EXTENSION = 'x-extension';

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

	/**
	 * @param list<ActionDescriptor> $extra Descriptors an extension would contribute.
	 */
	private function controller( InMemoryFlowRepository $flows, array $extra = array() ): FlowBuilderController {
		$catalog = new BuilderCatalog(
			new OpenAvailability(),
			new InMemoryConnectionStore(),
			array_merge( CoreActionDescriptors::all(), $extra ),
			CoreTriggers::all()
		);
		return new FlowBuilderController( $flows, $catalog, new FlowSerializer(), new FlowValidator( $catalog ) );
	}

	/** The Slack descriptor as the Automations add-on contributes it. */
	private function slack_descriptor(): ActionDescriptor {
		return new ActionDescriptor(
			'slack_post',
			'Post to Slack',
			'slack',
			self::EXTENSION,
			false,
			array(
				array( 'key' => 'channel', 'label' => 'Channel', 'type' => 'text' ),
				array( 'key' => 'text', 'label' => 'Message', 'type' => 'textarea' ),
			)
		);
	}

	private function email_payload( string $name, string $status ): array {
		return array(
			'name'   => $name,
			'type'   => 'welcome',
			'status' => $status,
			'steps'  => array( array( 'delay' => 0, 'action' => 'email', 'config' => array( 'subject' => 'Hi', 'body' => 'Body' ), 'conditions' => array() ) ),
		);
	}

	private function seeded(): InMemoryFlowRepository {
		$flows = new InMemoryFlowRepository();
		$flows->save( new FlowRecord( null, 'Welcome', 'welcome', FlowRecord::STATUS_ACTIVE, FlowRecord::SOURCE_TEMPLATE, array( new FlowStep( 0, 'Hi', 'Body' ) ) ) );
		$flows->save( new FlowRecord( null, 'Win back', 'win_back', FlowRecord::STATUS_DRAFT, FlowRecord::SOURCE_AI, array() ) );
		return $flows;
	}

	public function test_catalog_data_returns_the_three_sections(): void {
		$data = $this->controller( new InMemoryFlowRepository() )->catalog_data();

		$this->assertArrayHasKey( 'triggers', $data );
		$this->assertArrayHasKey( 'actions', $data );
		$this->assertArrayHasKey( 'conditions', $data );
	}

	public function test_flow_list_summarizes_each_flow(): void {
		$list = $this->controller( $this->seeded() )->flow_list();

		$this->assertCount( 2, $list );
		$this->assertSame( 'Welcome', $list[0]['name'] );
		$this->assertSame( 1, $list[0]['step_count'] );
		$this->assertSame( 'draft', $list[1]['status'] );
	}

	public function test_flow_data_returns_the_serialized_flow(): void {
		$flows = $this->seeded();
		$data  = $this->controller( $flows )->flow_data( 1 );

		$this->assertSame( 'Welcome', $data['name'] );
		$this->assertSame( 'email', $data['steps'][0]['action'] );
	}

	public function test_flow_data_is_null_for_an_unknown_id(): void {
		$this->assertNull( $this->controller( new InMemoryFlowRepository() )->flow_data( 999 ) );
	}

	public function test_registers_read_and_write_routes_under_the_namespace(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$routes ) {
				$routes[] = array( $namespace, $route, $args );
				return true;
			}
		);

		$this->controller( new InMemoryFlowRepository() )->register_routes();

		// GET /catalog, GET /flows, POST /flows, GET /flows/{id}, PUT /flows/{id}.
		$this->assertCount( 5, $routes );
		$methods = array();
		foreach ( $routes as $route ) {
			$this->assertSame( FlowBuilderController::NAMESPACE, $route[0] );
			$this->assertIsCallable( $route[2]['permission_callback'] );
			$methods[] = $route[2]['methods'];
		}
		sort( $methods );
		$this->assertSame( array( 'GET', 'GET', 'GET', 'POST', 'PUT' ), $methods );
	}

	public function test_permission_requires_manage_options(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );

		$this->assertTrue( $this->controller( new InMemoryFlowRepository() )->can_manage() );
	}

	public function test_create_flow_persists_and_returns_it(): void {
		$flows  = new InMemoryFlowRepository();
		$result = $this->controller( $flows )->create_flow( $this->email_payload( 'Welcome', 'active' ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'Welcome', $result['flow']['name'] );
		$this->assertSame( '', $result['blocked'] );
		$this->assertNotNull( $result['flow']['id'] );
		$this->assertCount( 1, $flows->all(), 'the flow was persisted' );
		$this->assertSame( FlowRecord::SOURCE_BUILDER, $flows->find( $result['flow']['id'] )->source );
	}

	public function test_create_flow_rejects_an_invalid_payload_without_saving(): void {
		$flows  = new InMemoryFlowRepository();
		$result = $this->controller( $flows )->create_flow( array( 'name' => '', 'type' => 'welcome', 'status' => 'draft', 'steps' => array() ) );

		$this->assertSame( 400, $result['status'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertCount( 0, $flows->all(), 'nothing is saved on a validation error' );
	}

	public function test_update_flow_saves_changes(): void {
		$flows = new InMemoryFlowRepository();
		$saved = $flows->save( new FlowRecord( null, 'Old', 'welcome', FlowRecord::STATUS_DRAFT, FlowRecord::SOURCE_TEMPLATE, array() ) );

		$result = $this->controller( $flows )->update_flow( $saved->id, $this->email_payload( 'New name', 'draft' ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'New name', $flows->find( $saved->id )->name );
		$this->assertSame( FlowRecord::SOURCE_TEMPLATE, $flows->find( $saved->id )->source, 'the source is preserved on update' );
	}

	public function test_update_flow_is_404_for_an_unknown_id(): void {
		$result = $this->controller( new InMemoryFlowRepository() )->update_flow( 999, $this->email_payload( 'X', 'draft' ) );

		$this->assertSame( 404, $result['status'] );
	}

	public function test_update_preserves_the_original_created_at(): void {
		$flows = new InMemoryFlowRepository();
		$saved = $flows->save( new FlowRecord( null, 'Old', 'welcome', FlowRecord::STATUS_DRAFT, FlowRecord::SOURCE_BUILDER, array(), '2020-01-02 03:04:05' ) );

		$this->controller( $flows )->update_flow( $saved->id, $this->email_payload( 'New', 'draft' ) );

		$this->assertSame( '2020-01-02 03:04:05', $flows->find( $saved->id )->created_at, 'the creation timestamp survives an edit' );
	}

	public function test_sanitize_payload_applies_field_type_aware_sanitizers(): void {
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => 'T:' . $s );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $s ) => 'A:' . $s );
		Functions\when( 'wp_kses_post' )->alias( static fn( $s ) => 'K:' . $s );

		$payload = array(
			'name'   => 'Hi',
			'type'   => 'welcome',
			'status' => 'draft',
			'steps'  => array(
				array( 'action' => 'email', 'config' => array( 'subject' => 's', 'body' => '<b>b</b>' ), 'conditions' => array() ),
				array( 'action' => 'slack_post', 'config' => array( 'channel' => '#o', 'text' => "l1\nl2" ), 'conditions' => array() ),
			),
		);

		$clean = $this->controller( new InMemoryFlowRepository(), array( $this->slack_descriptor() ) )->sanitize_payload( $payload );

		$this->assertSame( 'T:Hi', $clean['name'] );
		$this->assertSame( 'T:s', $clean['steps'][0]['config']['subject'], 'text field' );
		$this->assertSame( 'K:<b>b</b>', $clean['steps'][0]['config']['body'], 'email body is HTML → wp_kses_post' );
		$this->assertSame( 'T:#o', $clean['steps'][1]['config']['channel'], 'text field' );
		$this->assertSame( "A:l1\nl2", $clean['steps'][1]['config']['text'], 'textarea preserves newlines' );
	}

	public function test_every_valid_save_is_persisted_as_sent(): void {
		// Nothing in the plugin blocks a save — conditional gates included.
		$flows   = new InMemoryFlowRepository();
		$payload = array(
			'name'   => 'Gated',
			'type'   => 'welcome',
			'status' => 'active',
			'steps'  => array( array(
				'delay'      => 0,
				'action'     => 'email',
				'config'     => array( 'subject' => 'Hi', 'body' => 'Body' ),
				'conditions' => array( array( 'type' => 'cart_value_gt', 'value' => 50 ) ),
			) ),
		);

		$result = $this->controller( $flows )->create_flow( $payload );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '', $result['blocked'] );
		$this->assertSame( FlowRecord::STATUS_ACTIVE, $result['flow']['status'], 'a conditional flow activates' );
	}

	public function test_an_extension_can_adjust_a_pending_save_through_the_presave_filter(): void {
		$flows = new InMemoryFlowRepository();

		Filters\expectApplied( FlowBuilderController::FILTER_PRESAVE )
			->once()
			->andReturnUsing(
				static fn( array $pending ): array => array(
					'record'  => $pending['record']->with_status( FlowRecord::STATUS_PAUSED ),
					'blocked' => 'Saved, but not activated.',
				)
			);

		$result = $this->controller( $flows )->create_flow( $this->email_payload( 'Welcome', 'active' ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'Saved, but not activated.', $result['blocked'] );
		$this->assertSame( FlowRecord::STATUS_PAUSED, $flows->all()[0]->status );
	}
}
