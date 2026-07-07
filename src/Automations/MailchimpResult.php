<?php
/**
 * Outcome of a Mailchimp audience sync.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A Mailchimp sync's result: whether it was accepted, the member reference the
 * API returns (id or subscriber hash, used as the message's external ref), and a
 * human-readable error when it failed.
 */
final class MailchimpResult {

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
