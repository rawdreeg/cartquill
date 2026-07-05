<?php
/**
 * Raised when the AI proxy is unreachable or returns an error.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class ProxyException extends \RuntimeException {}
