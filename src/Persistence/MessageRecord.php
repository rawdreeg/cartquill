<?php
/**
 * A row in the `messages` table: one send plus its lifecycle status.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Mirrors the `messages` table. Status progresses queued -> sent on the
 * wp_mail path (later: opened/clicked via self-hosted tracking, and
 * delivered/bounced/complained once the Deliverability add-on is active).
 *
 * `channel` names the action that produced the row (email, slack_post, ...);
 * `recipient` always stays the customer's email so GDPR export/erase and
 * last-touch attribution keep keying on it, while `target` records the actual
 * destination the action reached (the same email, a phone number, a Slack
 * channel, ...). Only customer-facing channels count as attribution touches.
 */
final class MessageRecord {

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_SENT      = 'sent';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_OPENED    = 'opened';
	public const STATUS_CLICKED   = 'clicked';
	public const STATUS_DELIVERED = 'delivered';
	public const STATUS_BOUNCED   = 'bounced';
	public const STATUS_COMPLAINED = 'complained';

	public const CHANNEL_EMAIL = 'email';

	/**
	 * Channels that represent a customer touch — counted for last-touch
	 * attribution and engagement stats. Internal action rows (Slack, Sheets,
	 * Mailchimp) are excluded. Later slices extend this (e.g. sms_send).
	 *
	 * @var list<string>
	 */
	public const CUSTOMER_CHANNELS = array( self::CHANNEL_EMAIL );

	/**
	 * @param int|null    $id           Row id (null until persisted).
	 * @param int|null    $enrollment_id Owning enrollment.
	 * @param int         $flow_id      Owning flow.
	 * @param int         $step_index   Step within the flow.
	 * @param string      $recipient    Customer email (always, for GDPR/attribution).
	 * @param string      $sender       Sender/transport key that produced this send.
	 * @param string      $status       One of the STATUS_* constants.
	 * @param string|null $external_id  Transport message id, if any.
	 * @param string|null $sent_at      MySQL datetime the send was recorded.
	 * @param int         $attempts     Send attempts made so far (for retries).
	 * @param string      $channel      Action type that produced this row (default "email").
	 * @param string|null $target       Destination the action reached (email/phone/…), if any.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly ?int $enrollment_id,
		public readonly int $flow_id,
		public readonly int $step_index,
		public readonly string $recipient,
		public readonly string $sender,
		public readonly string $status,
		public readonly ?string $external_id = null,
		public readonly ?string $sent_at = null,
		public readonly int $attempts = 0,
		public readonly string $channel = self::CHANNEL_EMAIL,
		public readonly ?string $target = null,
	) {}

	/**
	 * Return a copy with the persisted id set.
	 */
	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->enrollment_id,
			$this->flow_id,
			$this->step_index,
			$this->recipient,
			$this->sender,
			$this->status,
			$this->external_id,
			$this->sent_at,
			$this->attempts,
			$this->channel,
			$this->target,
		);
	}

	/**
	 * Return a copy carrying a send outcome (status + external id + sent time).
	 *
	 * Used to update a claimed `queued` row once the action has responded.
	 */
	public function with_result( string $status, ?string $external_id, ?string $sent_at ): self {
		return new self(
			$this->id,
			$this->enrollment_id,
			$this->flow_id,
			$this->step_index,
			$this->recipient,
			$this->sender,
			$status,
			$external_id,
			$sent_at,
			$this->attempts,
			$this->channel,
			$this->target,
		);
	}

	/**
	 * Return a copy that has recorded another (failed) send attempt, staying
	 * queued for retry.
	 */
	public function with_attempt( int $attempts ): self {
		return new self(
			$this->id,
			$this->enrollment_id,
			$this->flow_id,
			$this->step_index,
			$this->recipient,
			$this->sender,
			self::STATUS_QUEUED,
			$this->external_id,
			$this->sent_at,
			$attempts,
			$this->channel,
			$this->target,
		);
	}

	/**
	 * Whether this row is a customer touch (email/SMS), eligible for attribution
	 * and engagement stats. Internal action rows are not.
	 */
	public function is_customer_channel(): bool {
		return in_array( $this->channel, self::CUSTOMER_CHANNELS, true );
	}
}
