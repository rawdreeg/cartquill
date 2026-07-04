<?php
/**
 * Applies a verified ESP webhook event to the messages + suppression state.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

use FlowForge\Compliance\SuppressionList;
use FlowForge\Persistence\MessageRecord;
use FlowForge\Persistence\MessageRepository;

/**
 * The tested core of webhook ingestion (no WordPress): given a decoded Resend
 * event, it moves the matching message row forward through
 * delivered → opened → clicked (never backwards) and marks bounced/complained.
 * A bounce or complaint additionally adds the recipient to the global
 * suppression list, so the very next send skips a dead or hostile address.
 */
final class WebhookProcessor {

	/** Resend event type → message status. */
	private const EVENT_STATUS = array(
		'email.sent'       => MessageRecord::STATUS_SENT,
		'email.delivered'  => MessageRecord::STATUS_DELIVERED,
		'email.opened'     => MessageRecord::STATUS_OPENED,
		'email.clicked'    => MessageRecord::STATUS_CLICKED,
		'email.bounced'    => MessageRecord::STATUS_BOUNCED,
		'email.complained' => MessageRecord::STATUS_COMPLAINED,
	);

	/** How far a positive lifecycle status has progressed (higher = further). */
	private const RANK = array(
		MessageRecord::STATUS_QUEUED    => 0,
		MessageRecord::STATUS_SENT      => 1,
		MessageRecord::STATUS_DELIVERED => 2,
		MessageRecord::STATUS_OPENED    => 3,
		MessageRecord::STATUS_CLICKED   => 4,
	);

	private const SUPPRESSING = array(
		MessageRecord::STATUS_BOUNCED,
		MessageRecord::STATUS_COMPLAINED,
	);

	public function __construct(
		private readonly MessageRepository $messages,
		private readonly SuppressionList $suppression,
	) {}

	/**
	 * @param array<string, mixed> $event A decoded Resend webhook event.
	 *
	 * @return bool Whether the event was recognised and applied.
	 */
	public function process( array $event ): bool {
		$type = (string) ( $event['type'] ?? '' );
		if ( ! isset( self::EVENT_STATUS[ $type ] ) ) {
			return false;
		}

		$status      = self::EVENT_STATUS[ $type ];
		$data        = (array) ( $event['data'] ?? array() );
		$external_id = (string) ( $data['email_id'] ?? $data['id'] ?? '' );
		$message     = $this->messages->find_by_external_id( $external_id );

		if ( in_array( $status, self::SUPPRESSING, true ) ) {
			$recipient = null !== $message ? $message->recipient : $this->recipient_from( $data );
			if ( '' !== $recipient ) {
				$reason = MessageRecord::STATUS_BOUNCED === $status ? 'bounce' : 'complaint';
				$this->suppression->suppress( $recipient, $reason );
			}
			if ( null !== $message && null !== $message->id ) {
				$this->messages->update_status( $message->id, $status );
			}
			return true;
		}

		// Positive lifecycle event: advance the status but never regress it.
		if ( null !== $message && null !== $message->id && $this->advances( $message->status, $status ) ) {
			$this->messages->update_status( $message->id, $status );
		}
		return true;
	}

	private function advances( string $current, string $next ): bool {
		return ( self::RANK[ $next ] ?? 0 ) > ( self::RANK[ $current ] ?? 0 );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function recipient_from( array $data ): string {
		$to = $data['to'] ?? '';
		if ( is_array( $to ) ) {
			$to = $to[0] ?? '';
		}
		return (string) $to;
	}
}
