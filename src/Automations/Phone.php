<?php
/**
 * Phone-number normalization for SMS suppression keys.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Canonicalises a phone number so the number captured from an order and the
 * number reported by Twilio (STOP replies, delivery) resolve to the same
 * suppression key. Strips everything but digits, keeping a single leading `+`.
 * This is deliberately not full E.164 parsing (no country inference); it only
 * needs to be consistent across the capture and STOP paths.
 */
final class Phone {

	public static function normalize( string $raw ): string {
		$raw    = trim( $raw );
		$plus   = str_starts_with( $raw, '+' ) ? '+' : '';
		$digits = preg_replace( '/\D+/', '', $raw );
		return '' === (string) $digits ? '' : $plus . $digits;
	}
}
