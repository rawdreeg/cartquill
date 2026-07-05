<?php
/**
 * Step condition evaluation: proceed / exit / skip.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Engine\ConditionEvaluator;
use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\EnrollmentRecord;
use CartQuill\Tests\Fake\FakeCustomerActivity;
use PHPUnit\Framework\TestCase;

final class ConditionEvaluatorTest extends TestCase {

	private const ENROLLED_AT = '2023-11-14 22:13:20'; // 1_700_000_000 UTC

	private FakeCustomerActivity $activity;
	private ConditionEvaluator $evaluator;

	protected function setUp(): void {
		$this->activity  = new FakeCustomerActivity();
		$this->evaluator = new ConditionEvaluator( $this->activity );
	}

	private function enrollment(): EnrollmentRecord {
		return new EnrollmentRecord(
			id: 1,
			flow_id: 1,
			customer_email: 'buyer@example.com',
			created_at: self::ENROLLED_AT,
		);
	}

	public function test_no_conditions_proceeds(): void {
		$step = new FlowStep( 0, 'Hi', 'body' );
		$this->assertSame( ConditionEvaluator::PROCEED, $this->evaluator->decide( $step, $this->enrollment() ) );
	}

	public function test_exit_if_ordered_exits_when_customer_ordered_since_enrollment(): void {
		$this->activity->record_order( 'buyer@example.com', 1_700_000_500 );
		$step = new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'exit_if_ordered' ) ) );

		$this->assertSame( ConditionEvaluator::EXIT, $this->evaluator->decide( $step, $this->enrollment() ) );
	}

	public function test_exit_if_ordered_proceeds_when_no_order(): void {
		$step = new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'exit_if_ordered' ) ) );
		$this->assertSame( ConditionEvaluator::PROCEED, $this->evaluator->decide( $step, $this->enrollment() ) );
	}

	public function test_order_before_enrollment_does_not_trigger_exit(): void {
		$this->activity->record_order( 'buyer@example.com', 1_699_999_000 ); // before enrollment
		$step = new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'exit_if_ordered' ) ) );

		$this->assertSame( ConditionEvaluator::PROCEED, $this->evaluator->decide( $step, $this->enrollment() ) );
	}

	public function test_skip_action_returns_skip(): void {
		$this->activity->record_order( 'buyer@example.com', 1_700_000_500 );
		$step = new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'exit_if_ordered', 'action' => 'skip' ) ) );

		$this->assertSame( ConditionEvaluator::SKIP, $this->evaluator->decide( $step, $this->enrollment() ) );
	}

	public function test_unknown_condition_type_proceeds(): void {
		$step = new FlowStep( 0, 'Hi', 'body', array( array( 'type' => 'made_up' ) ) );
		$this->assertSame( ConditionEvaluator::PROCEED, $this->evaluator->decide( $step, $this->enrollment() ) );
	}
}
