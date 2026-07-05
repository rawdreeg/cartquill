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

final class ResendException extends \RuntimeException {}
