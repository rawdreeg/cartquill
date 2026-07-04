<?php
/**
 * The tracer-bullet dispatcher: renders one step, sends it, records it.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

use FlowForge\Flow\FlowDefinition;
use FlowForge\Flow\Renderer;
use FlowForge\Model\Message;
use FlowForge\Model\SendResult;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Persistence\MessageRepository;
use FlowForge\Sender\SenderInterface;
use FlowForge\Settings\Settings;
use FlowForge\Support\Clock;

/**
 * Proves the whole path works end-to-end for a single step:
 * render -> SenderInterface::send() -> record a `messages` row.
 *
 * The full engine (multi-step, Action Scheduler, conditions, idempotency,
 * suppression) is built in the engine slice and supersedes this. Everything the
 * engine will need is already injected as a seam here.
 */
final class SpineDispatcher {

	public function __construct(
		private readonly SenderInterface $sender,
		private readonly MessageRepository $messages,
		private readonly Renderer $renderer,
		private readonly Settings $settings,
		private readonly Clock $clock,
	) {}

	/**
	 * Render and send one step of a flow, recording the outcome.
	 *
	 * @param FlowDefinition $flow          The flow being run.
	 * @param int            $step_index    Which step to send.
	 * @param string         $recipient     Recipient email.
	 * @param int|null       $enrollment_id Owning enrollment, if one exists yet.
	 *
	 * @return MessageRecord The persisted record (status `sent` or `failed`).
	 */
	public function dispatch( FlowDefinition $flow, int $step_index, string $recipient, ?int $enrollment_id = null ): MessageRecord {
		$step = $flow->step( $step_index );
		if ( null === $step ) {
			throw new \InvalidArgumentException( "Flow has no step at index {$step_index}." );
		}

		$context = array(
			'customer_email' => $recipient,
			'store_name'     => $this->settings->from_name(),
		);

		$message = new Message(
			to: $recipient,
			subject: $this->renderer->render( $step->subject, $context ),
			body: $this->renderer->render( $step->body, $context ),
			from_name: $this->settings->from_name(),
			from_email: $this->settings->from_email(),
			enrollment_id: $enrollment_id,
			flow_id: $flow->id,
			step_index: $step_index,
		);

		$result = $this->sender->send( $message );

		$status = $result->is_accepted()
			? MessageRecord::STATUS_SENT
			: MessageRecord::STATUS_FAILED;

		$record = new MessageRecord(
			id: null,
			enrollment_id: $enrollment_id,
			flow_id: $flow->id,
			step_index: $step_index,
			recipient: $recipient,
			sender: $this->sender->key(),
			status: $status,
			external_id: $result->external_id,
			sent_at: $this->clock->now_mysql(),
		);

		return $this->messages->save( $record );
	}
}
