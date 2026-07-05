<?php
/**
 * An email to be sent through the SenderInterface.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Immutable description of a single email the engine wants sent.
 *
 * The engine builds a Message and hands it to a SenderInterface. The Message
 * is deliberately ignorant of *how* it will be sent — that is the sender's job.
 */
final class Message {

	/**
	 * @param string                $to           Recipient email address.
	 * @param string                $subject      Email subject line.
	 * @param string                $body         Rendered email body (HTML).
	 * @param string                $from_name    Sender display name.
	 * @param string                $from_email   Sender email address.
	 * @param array<string, string> $headers      Additional headers, keyed by name.
	 * @param string|null           $unsubscribe  Unsubscribe target for the List-Unsubscribe header (URL or mailto:).
	 * @param int|null              $enrollment_id Owning enrollment, if any.
	 * @param int|null              $flow_id      Owning flow, if any.
	 * @param int|null              $step_index   Step index within the flow, if any.
	 */
	public function __construct(
		public readonly string $to,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $from_name = '',
		public readonly string $from_email = '',
		public readonly array $headers = array(),
		public readonly ?string $unsubscribe = null,
		public readonly ?int $enrollment_id = null,
		public readonly ?int $flow_id = null,
		public readonly ?int $step_index = null,
	) {}

	/**
	 * Build the RFC-style header lines a mail transport expects.
	 *
	 * @return list<string>
	 */
	public function header_lines(): array {
		$lines = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( '' !== $this->from_email ) {
			$from      = '' !== $this->from_name
				? sprintf( '%s <%s>', $this->from_name, $this->from_email )
				: $this->from_email;
			$lines[] = 'From: ' . $from;
		}

		if ( null !== $this->unsubscribe && '' !== $this->unsubscribe ) {
			$lines[] = 'List-Unsubscribe: <' . $this->unsubscribe . '>';
			// RFC 8058 one-click: compliant clients POST rather than GET, so
			// link prefetchers/scanners can't silently unsubscribe the user.
			if ( 1 === preg_match( '/^https?:\/\//i', $this->unsubscribe ) ) {
				$lines[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
			}
		}

		foreach ( $this->headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		return $lines;
	}
}
