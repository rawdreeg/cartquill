<?php
/**
 * Outcome of a Google Sheets append.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A Sheets append's result: whether it was accepted, the updated range the API
 * reports (e.g. "Sheet1!A5:C5", used as the message's external ref), and a
 * human-readable error when it failed.
 */
final class SheetsResult {

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
