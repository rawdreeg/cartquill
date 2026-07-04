<?php
/**
 * Raised when the Resend API is unreachable or rejects a request.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

final class ResendException extends \RuntimeException {}
