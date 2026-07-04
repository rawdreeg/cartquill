<?php
/**
 * Builds the Message for a step: renders templates, guarantees the unsubscribe.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

use FlowForge\Compliance\UnsubscribeLink;
use FlowForge\Flow\FlowStep;
use FlowForge\Flow\Renderer;
use FlowForge\Model\Message;
use FlowForge\Settings\Settings;
use FlowForge\Tracking\LinkTracker;
use FlowForge\Tracking\NullLinkTracker;

/**
 * Turns a step + recipient into a ready-to-send Message. Every message it
 * produces carries an unsubscribe footer and List-Unsubscribe header, honoring
 * the locked "unsubscribe link on every email" rule regardless of the template.
 */
final class MessageComposer {

	private readonly LinkTracker $tracker;

	public function __construct(
		private readonly Renderer $renderer,
		private readonly Settings $settings,
		?LinkTracker $tracker = null,
		private readonly ?UnsubscribeLink $unsubscribe_link = null,
	) {
		$this->tracker = $tracker ?? new NullLinkTracker();
	}

	/**
	 * @param int $message_id The claimed message row id, used to tie open/click
	 *                        tracking to this send (0 disables tracking).
	 */
	public function compose( FlowStep $step, string $recipient, int $flow_id, int $step_index, ?int $enrollment_id, int $message_id = 0 ): Message {
		$unsubscribe = $this->unsubscribe_target( $recipient );

		$context = array(
			'customer_email'  => $recipient,
			'store_name'      => $this->settings->from_name(),
			'unsubscribe_url' => $unsubscribe,
		);

		// Render content, wrap its links for click tracking, add the unsubscribe
		// footer (not wrapped — it is a mailto:), then the open pixel last.
		$body = $this->renderer->render( $step->body, $context );
		$body = $this->tracker->wrap_links( $body, $message_id );
		$body = $this->with_unsubscribe_footer( $body, $unsubscribe );
		$body .= $this->tracker->pixel_html( $message_id );

		return new Message(
			to: $recipient,
			subject: $this->renderer->render( $step->subject, $context ),
			body: $body,
			from_name: $this->settings->from_name(),
			from_email: $this->settings->from_email(),
			unsubscribe: '' !== $unsubscribe ? $unsubscribe : null,
			enrollment_id: $enrollment_id,
			flow_id: $flow_id,
			step_index: $step_index,
		);
	}

	/**
	 * The unsubscribe target for this recipient: a real one-click HTTP link when
	 * available, falling back to a mailto to the store address.
	 */
	private function unsubscribe_target( string $recipient ): string {
		if ( null !== $this->unsubscribe_link ) {
			return $this->unsubscribe_link->url( $recipient );
		}
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
