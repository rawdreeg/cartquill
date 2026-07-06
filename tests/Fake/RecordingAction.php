<?php
/**
 * Test double: a configurable action that records its execute() calls.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Fake;

use CartQuill\Action\ActionContext;
use CartQuill\Action\ActionInterface;
use CartQuill\Model\SendResult;

/**
 * Stands in for add-on actions (Slack, Sheets, SMS, ...) so engine tests can
 * assert channel-aware suppression, dead-lettering, and per-channel recording
 * without a real transport. Configure its type, whether it is customer-facing,
 * and its suppression/record target.
 */
final class RecordingAction implements ActionInterface {

	/** @var list<ActionContext> */
	public array $calls = array();

	private ?SendResult $next_result = null;

	public function __construct(
		private readonly string $type,
		private readonly bool $customer_facing = false,
		private readonly ?string $target = null,
		private readonly string $sender_key = 'fake-action',
	) {}

	public function type(): string {
		return $this->type;
	}

	public function sender_key(): string {
		return $this->sender_key;
	}

	public function is_customer_facing(): bool {
		return $this->customer_facing;
	}

	public function target( ActionContext $context ): ?string {
		return $this->target;
	}

	public function execute( ActionContext $context ): SendResult {
		$this->calls[] = $context;
		return $this->next_result ?? SendResult::accepted( $this->type . '-1' );
	}

	/**
	 * Force the outcome of the next execute() call.
	 */
	public function will_return( SendResult $result ): void {
		$this->next_result = $result;
	}

	public function count(): int {
		return count( $this->calls );
	}
}
