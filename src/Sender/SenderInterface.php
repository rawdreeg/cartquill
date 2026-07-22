<?php
/**
 * The single sending seam for the whole plugin.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Sender;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Model\Message;
use CartQuill\Model\SendResult;

/**
 * Every way CartQuill sends email implements this interface.
 *
 * Core ships WpMailSender. Paid add-ons register additional senders
 * (e.g. an ESP-backed sender) through the register_sender() hook. The flow
 * engine only ever talks to this interface, so it stays ignorant of *how* mail
 * is actually sent. Tests inject a FakeSender.
 */
interface SenderInterface {

	/**
	 * Stable machine key for this sender (e.g. "wp_mail", "resend").
	 *
	 * Recorded on the message row so lifecycle events can be routed back to the
	 * sender that produced them.
	 */
	public function key(): string;

	/**
	 * Attempt to send a message.
	 *
	 * Must not throw for ordinary delivery failures — return a failed
	 * SendResult instead so the engine can record the outcome.
	 */
	public function send( Message $message ): SendResult;
}
