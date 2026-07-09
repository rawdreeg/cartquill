<?php
/**
 * Raised when the Resend API is unreachable or rejects a request.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Carries the HTTP status of the failed Resend call when there was one, so
 * callers can tell a definitive rejection (e.g. 404 domain-not-found) from a
 * transient transport blip (null status). Null means the request never got an
 * HTTP response (timeout, DNS, connection error).
 */
final class ResendException extends \RuntimeException {

	public function __construct( string $message, private readonly ?int $status = null ) {
		parent::__construct( $message );
	}

	public function status(): ?int {
		return $this->status;
	}
}
