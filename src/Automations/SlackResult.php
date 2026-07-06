<?php
/**
 * Outcome of a Slack post.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A Slack post's result: whether it was accepted, an optional message reference
 * (incoming webhooks acknowledge with "ok" and no ts, so this is best-effort),
 * and a human-readable error when it failed.
 */
final class SlackResult {

	private function __construct(
		public readonly bool $ok,
		public readonly ?string $ref = null,
		public readonly ?string $error = null,
	) {}

	public static function ok( ?string $ref = null ): self {
		return new self( true, $ref, null );
	}

	public static function failed( string $error ): self {
		return new self( false, null, $error );
	}
}
