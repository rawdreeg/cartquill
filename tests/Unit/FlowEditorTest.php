<?php
/**
 * The flow editor transform, and that an edit is honored at send time.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Engine\Enroller;
use CartQuill\Engine\MessageComposer;
use CartQuill\Engine\StepRunner;
use CartQuill\Flow\FlowEditor;
use CartQuill\Flow\FlowInstaller;
use CartQuill\Flow\FlowLibrary;
use CartQuill\Flow\DefaultFlows;
use CartQuill\Flow\Renderer;
use CartQuill\Persistence\FlowRecord;
use CartQuill\Persistence\InMemoryEnrollmentRepository;
use CartQuill\Persistence\InMemoryFlowRepository;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Scheduling\ArrayScheduler;
use CartQuill\Sender\FakeSender;
use CartQuill\Settings\ArraySettings;
use CartQuill\Support\FixedClock;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use PHPUnit\Framework\TestCase;

final class FlowEditorTest extends TestCase {

	public function test_apply_updates_name_status_and_steps(): void {
		$flow    = DefaultFlows::welcome()->with_id( 1 );
		$updated = ( new FlowEditor() )->apply(
			$flow,
			array(
				'name'   => 'My welcome',
				'status' => 'active',
				'steps'  => array(
					array( 'delay' => 0, 'subject' => 'Hi there', 'body' => '<p>Edited</p>', 'exit_if_ordered' => '1' ),
				),
			)
		);

		$this->assertSame( 'My welcome', $updated->name );
		$this->assertTrue( $updated->is_active() );
		$this->assertCount( 1, $updated->steps );
		$this->assertSame( 'Hi there', $updated->steps[0]->subject );
		$this->assertSame( array( array( 'type' => 'exit_if_ordered' ) ), $updated->steps[0]->conditions );
		$this->assertSame( 1, $updated->id, 'id/type/source preserved' );
		$this->assertSame( DefaultFlows::TYPE_WELCOME, $updated->type );
	}

	public function test_apply_keeps_existing_steps_when_none_submitted(): void {
		$flow    = DefaultFlows::welcome()->with_id( 1 );
		$updated = ( new FlowEditor() )->apply( $flow, array( 'name' => 'Renamed' ) );

		$this->assertCount( 2, $updated->steps );
		$this->assertSame( 'Renamed', $updated->name );
	}

	public function test_apply_ignores_an_invalid_status(): void {
		$flow    = DefaultFlows::welcome( FlowRecord::STATUS_DRAFT )->with_id( 1 );
		$updated = ( new FlowEditor() )->apply( $flow, array( 'status' => 'bogus' ) );
		$this->assertSame( FlowRecord::STATUS_DRAFT, $updated->status );
	}

	public function test_apply_removes_steps_flagged_for_removal(): void {
		$flow    = DefaultFlows::welcome()->with_id( 1 ); // 2 steps
		$updated = ( new FlowEditor() )->apply(
			$flow,
			array(
				'steps' => array(
					array( 'delay' => 0, 'subject' => 'Keep', 'body' => 'a' ),
					array( 'delay' => 100, 'subject' => 'Drop', 'body' => 'b', 'remove' => '1' ),
				),
			)
		);

		$this->assertCount( 1, $updated->steps );
		$this->assertSame( 'Keep', $updated->steps[0]->subject );
	}

	public function test_apply_can_grow_the_flow_with_new_steps(): void {
		$flow    = DefaultFlows::welcome()->with_id( 1 ); // 2 steps
		$updated = ( new FlowEditor() )->apply(
			$flow,
			array(
				'steps' => array(
					array( 'delay' => 0, 'subject' => 'One', 'body' => 'a' ),
					array( 'delay' => 3600, 'subject' => 'Two', 'body' => 'b' ),
					array( 'delay' => 7200, 'subject' => 'Three', 'body' => 'c' ),
				),
			)
		);

		$this->assertCount( 3, $updated->steps, 'editor can add steps' );
		$this->assertSame( 'Three', $updated->steps[2]->subject );
	}

	public function test_install_activate_edit_is_honored_at_send_time(): void {
		$flows       = new InMemoryFlowRepository();
		$enrollments = new InMemoryEnrollmentRepository();
		$messages    = new InMemoryMessageRepository();
		$sender      = new FakeSender();
		$scheduler   = new ArrayScheduler();
		$clock       = new FixedClock( 1_700_000_000 );

		$installer = new FlowInstaller( new FlowLibrary(), $flows );
		$editor    = new FlowEditor();
		$enroller  = new Enroller( $enrollments, $scheduler, $clock );
		$runner    = new StepRunner(
			$flows,
			$enrollments,
			$messages,
			new MessageComposer( new Renderer(), new ArraySettings( 'Acme', 'hello@acme.test' ) ),
			$sender,
			new ArraySuppressionList(),
			new ConditionEvaluator( new FakeCustomerActivity() ),
			$scheduler,
			$clock,
		);

		// Install (draft): enrollment is a no-op until it is activated.
		$flow = $installer->install( DefaultFlows::TYPE_WELCOME );
		$this->assertNull( $enroller->enroll( $flow, 'buyer@example.com' ), 'draft flow does not enroll' );

		// Edit the first step's copy and activate.
		$edited = $editor->apply(
			$flow,
			array(
				'status' => 'active',
				'steps'  => array(
					array( 'delay' => 0, 'subject' => 'Custom subject', 'body' => '<p>Custom body</p>' ),
				),
			)
		);
		$flows->save( $edited );

		// Now enrollment works and the edited copy is what gets sent.
		$enroller->enroll( $flows->find( (int) $flow->id ), 'buyer@example.com' );
		$scheduler->run_due( $clock->now(), fn( int $e, int $s ) => $runner->run_step( $e, $s ) );

		$this->assertSame( 1, $sender->count() );
		$this->assertSame( 'Custom subject', $sender->last()->subject );
		$this->assertStringContainsString( 'Custom body', $sender->last()->body );
	}
}
