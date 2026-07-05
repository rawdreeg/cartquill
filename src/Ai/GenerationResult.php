<?php
/**
 * Outcome of an AI generation attempt.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Persistence\FlowRecord;

/**
 * Lets the caller distinguish success from the graceful-degrade cases (not
 * licensed, rate-limited, proxy error) without exceptions leaking into the UI.
 */
final class GenerationResult {

	public const OK           = 'ok';
	public const NOT_LICENSED = 'not_licensed';
	public const RATE_LIMITED = 'rate_limited';
	public const ERROR        = 'error';

	private function __construct(
		public readonly string $status,
		public readonly ?FlowRecord $flow,
		public readonly string $message,
	) {}

	public static function ok( FlowRecord $flow ): self {
		return new self( self::OK, $flow, '' );
	}

	public static function not_licensed(): self {
		return new self( self::NOT_LICENSED, null, 'The AI Flow Generation add-on is not active.' );
	}

	public static function rate_limited(): self {
		return new self( self::RATE_LIMITED, null, 'AI usage limit reached. Try again later.' );
	}

	public static function error( string $message ): self {
		return new self( self::ERROR, null, $message );
	}

	public function is_ok(): bool {
		return self::OK === $this->status;
	}
}
