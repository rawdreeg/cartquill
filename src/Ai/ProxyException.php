<?php
/**
 * Raised when the AI proxy is unreachable or returns an error.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class ProxyException extends \RuntimeException {}
