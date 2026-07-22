<?php
/**
 * Outcome of a SenderInterface::send() call.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The minimum contract every sender must return: an external id (when the
 * transport provides one) plus an accepted/failed status.
 *
 * The engine records this against the message row so the original send can be
 * correlated back to its message later.
 */
final class SendResult {

	public const STATUS_ACCEPTED = 'accepted';
	public const STATUS_FAILED   = 'failed';

	/**
	 * @param string      $status     One of the STATUS_* constants.
	 * @param string|null $external_id Transport message id, if any.
	 * @param string|null $error      Human-readable failure reason, if failed.
	 */
	private function __construct(
		public readonly string $status,
		public readonly ?string $external_id = null,
		public readonly ?string $error = null,
	) {}

	/**
	 * The transport accepted the message for delivery.
	 */
	public static function accepted( ?string $external_id = null ): self {
		return new self( self::STATUS_ACCEPTED, $external_id, null );
	}

	/**
	 * The transport rejected or failed to accept the message.
	 */
	public static function failed( string $error, ?string $external_id = null ): self {
		return new self( self::STATUS_FAILED, $external_id, $error );
	}

	public function is_accepted(): bool {
		return self::STATUS_ACCEPTED === $this->status;
	}
}
