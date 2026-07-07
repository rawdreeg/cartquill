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

	/**
	 * @param array<string, mixed> $context
	 */
	private function enrollment( array $context = array() ): EnrollmentRecord {
		return new EnrollmentRecord(
			id: 1,
			flow_id: 1,
			customer_email: 'buyer@example.com',
			created_at: self::ENROLLED_AT,
			context: $context,
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

	public function test_require_context_gate_skips_when_value_absent(): void {
		$step = new FlowStep( 0, 'Text', 'tracking', array( array( 'type' => 'require_context', 'key' => 'phone' ) ) );

		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment() ),
			'a gate whose predicate is unmet skips the step'
		);
	}

	public function test_require_context_gate_proceeds_when_value_present(): void {
		$step = new FlowStep( 0, 'Text', 'tracking', array( array( 'type' => 'require_context', 'key' => 'phone' ) ) );

		$this->assertSame(
			ConditionEvaluator::PROCEED,
			$this->evaluator->decide( $step, $this->enrollment( array( 'phone' => '+15551230000' ) ) )
		);
	}

	public function test_require_context_gt_gates_on_a_threshold(): void {
		$step = new FlowStep( 0, 'Recover', 'come back', array( array( 'type' => 'require_context', 'key' => 'cart_value', 'gt' => 50 ) ) );

		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment( array( 'cart_value' => 40 ) ) ),
			'a below-threshold value skips'
		);
		$this->assertSame(
			ConditionEvaluator::PROCEED,
			$this->evaluator->decide( $step, $this->enrollment( array( 'cart_value' => 75 ) ) ),
			'an above-threshold value proceeds'
		);
	}

	public function test_first_time_customer_gate_proceeds_on_a_first_order(): void {
		$this->activity->record_order( 'buyer@example.com', 1_700_000_000 ); // their only order
		$step = new FlowStep( 0, '', '', array( array( 'type' => 'first_time_customer' ) ), 'slack_post' );

		$this->assertSame(
			ConditionEvaluator::PROCEED,
			$this->evaluator->decide( $step, $this->enrollment() )
		);
	}

	public function test_first_time_customer_gate_skips_a_returning_customer(): void {
		$this->activity->record_order( 'buyer@example.com', 1_699_000_000 );
		$this->activity->record_order( 'buyer@example.com', 1_700_000_000 ); // second order
		$step = new FlowStep( 0, '', '', array( array( 'type' => 'first_time_customer' ) ), 'slack_post' );

		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment() ),
			'a customer with more than one order is not first-time'
		);
	}

	public function test_marketing_opt_in_gate(): void {
		$step = new FlowStep( 0, '', '', array( array( 'type' => 'marketing_opt_in' ) ), 'mailchimp_sync' );

		$this->assertSame(
			ConditionEvaluator::PROCEED,
			$this->evaluator->decide( $step, $this->enrollment( array( 'marketing_opt_in' => true ) ) )
		);
		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment( array( 'marketing_opt_in' => false ) ) ),
			'a non-opted-in customer is skipped'
		);
		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment() ),
			'no opt-in in context also skips'
		);
	}

	public function test_has_phone_gate(): void {
		$step = new FlowStep( 0, '', '', array( array( 'type' => 'has_phone' ) ), 'sms_send' );

		$this->assertSame(
			ConditionEvaluator::PROCEED,
			$this->evaluator->decide( $step, $this->enrollment( array( 'phone' => '+15551112222' ) ) )
		);
		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment( array( 'phone' => '' ) ) ),
			'an empty phone skips'
		);
		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment() ),
			'no phone in context skips'
		);
	}

	public function test_cart_value_gt_gate(): void {
		$step = new FlowStep( 0, 'Come back', 'body', array( array( 'type' => 'cart_value_gt', 'value' => 50 ) ) );

		$this->assertSame(
			ConditionEvaluator::PROCEED,
			$this->evaluator->decide( $step, $this->enrollment( array( 'cart_value' => 75.0 ) ) )
		);
		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment( array( 'cart_value' => 40.0 ) ) ),
			'a cart at or below the threshold skips the recovery step'
		);
		$this->assertSame(
			ConditionEvaluator::SKIP,
			$this->evaluator->decide( $step, $this->enrollment( array( 'cart_value' => 50.0 ) ) ),
			'exactly the threshold is not greater-than'
		);
	}
}
