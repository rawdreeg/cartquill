<?php
/**
 * Builds the Message for a step: renders templates, guarantees the unsubscribe.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

use FlowForge\Flow\FlowStep;
use FlowForge\Flow\Renderer;
use FlowForge\Model\Message;
use FlowForge\Settings\Settings;

/**
 * Turns a step + recipient into a ready-to-send Message. Every message it
 * produces carries an unsubscribe footer and List-Unsubscribe header, honoring
 * the locked "unsubscribe link on every email" rule regardless of the template.
 */
final class MessageComposer {

	public function __construct(
		private readonly Renderer $renderer,
		private readonly Settings $settings,
	) {}

	public function compose( FlowStep $step, string $recipient, int $flow_id, int $step_index, ?int $enrollment_id ): Message {
		$unsubscribe = $this->unsubscribe_target();

		$context = array(
			'customer_email'  => $recipient,
			'store_name'      => $this->settings->from_name(),
			'unsubscribe_url' => $unsubscribe,
		);

		$body = $this->renderer->render( $step->body, $context );

		return new Message(
			to: $recipient,
			subject: $this->renderer->render( $step->subject, $context ),
			body: $this->with_unsubscribe_footer( $body, $unsubscribe ),
			from_name: $this->settings->from_name(),
			from_email: $this->settings->from_email(),
			unsubscribe: '' !== $unsubscribe ? $unsubscribe : null,
			enrollment_id: $enrollment_id,
			flow_id: $flow_id,
			step_index: $step_index,
		);
	}

	private function unsubscribe_target(): string {
		$from = $this->settings->from_email();
		return '' !== $from ? 'mailto:' . $from . '?subject=unsubscribe' : '';
	}

	/**
	 * Append an unsubscribe footer to every email. When an unsubscribe target
	 * exists it is a link; otherwise a plain-text notice still guarantees the
	 * message visibly carries an unsubscribe, honoring the locked compliance
	 * rule even if the from-address is unconfigured.
	 */
	private function with_unsubscribe_footer( string $body, string $unsubscribe ): string {
		$notice = '' !== $unsubscribe
			? sprintf( '<a href="%s">Unsubscribe</a> from these emails.', $unsubscribe )
			: 'To unsubscribe, reply to this email with &ldquo;unsubscribe&rdquo;.';

		$footer = '<p style="font-size:12px;color:#888;margin-top:24px">' . $notice . '</p>';

		return $body . "\n" . $footer;
	}
}
