<?php
/**
 * Outcome of a Twilio SMS send.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * An SMS send's result: whether Twilio accepted it, the message SID (recorded as
 * the message's external id for later correlation), and a human-readable error
 * when it failed.
 */
final class SmsResult {

	private function __construct(
		public readonly bool $ok,
		public readonly ?string $sid = null,
		public readonly ?string $error = null,
	) {}

	public static function ok( ?string $sid = null ): self {
		return new self( true, $sid, null );
	}

	public static function failed( string $error ): self {
		return new self( false, null, $error );
	}
}
