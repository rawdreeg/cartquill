<?php
/**
 * Core sender: delivers via WordPress wp_mail().
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
 * Sends through wp_mail(), respecting any SMTP plugin the site already runs.
 *
 * wp_mail() reports only whether the message was handed off (true/false) — it
 * has no external id and no delivery/bounce signal. That is by design: the free
 * core caps message lifecycle at "sent". Real delivery data requires the paid
 * Deliverability add-on (a different SenderInterface implementation).
 */
final class WpMailSender implements SenderInterface {

	public function key(): string {
		return 'wp_mail';
	}

	public function send( Message $message ): SendResult {
		$sent = \wp_mail(
			$message->to,
			$message->subject,
			$message->body,
			$message->header_lines()
		);

		if ( $sent ) {
			// wp_mail() has no external id; the accepted status alone marks "sent".
			return SendResult::accepted( null );
		}

		return SendResult::failed( 'wp_mail() returned false' );
	}
}
