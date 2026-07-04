<?php
/**
 * Test double: records send() calls instead of sending real email.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Sender;

use FlowForge\Model\Message;
use FlowForge\Model\SendResult;

/**
 * The primary test seam.
 *
 * Injected in place of a real sender so tests can assert exactly which
 * messages the engine produced — recipients, content, timing — without sending
 * anything. Lives in src/ (not tests/) so add-ons and integration tests can
 * reuse it too.
 */
final class FakeSender implements SenderInterface {

	/** @var list<Message> */
	private array $sent = array();

	/** Optional canned result; defaults to accepted with a synthetic id. */
	private ?SendResult $next_result = null;

	/** Monotonic counter used to mint synthetic external ids. */
	private int $counter = 0;

	public function key(): string {
		return 'fake';
	}

	public function send( Message $message ): SendResult {
		$this->sent[] = $message;

		if ( null !== $this->next_result ) {
			$result           = $this->next_result;
			$this->next_result = null;
			return $result;
		}

		++$this->counter;
		return SendResult::accepted( 'fake-' . $this->counter );
	}

	/**
	 * Force the outcome of the next send() call (e.g. to simulate a failure).
	 */
	public function will_return( SendResult $result ): void {
		$this->next_result = $result;
	}

	/**
	 * All messages handed to send(), in order.
	 *
	 * @return list<Message>
	 */
	public function sent_messages(): array {
		return $this->sent;
	}

	public function count(): int {
		return count( $this->sent );
	}

	public function last(): ?Message {
		return $this->sent[ count( $this->sent ) - 1 ] ?? null;
	}
}
