<?php
/**
 * Decides whether a Resend-verified sending domain covers a From-address.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Resend rejects (554) any send whose From-domain it has not verified. The
 * add-on verifies one sending domain ({@see EspSettings::domain()}) but the
 * actual From-address comes from a separate setting ({@see \CartQuill\Settings\Settings::from_email()}),
 * so the two can silently diverge: a store verifies mail.example.com yet leaves
 * From = owner@gmail.com, and every send bounces while the wizard shows
 * "Verified". This guard is the single source of truth for whether they agree —
 * the From-domain must equal the verified domain or be a subdomain of it.
 *
 * Pure and WordPress-free so it is testable DB-free and reused identically by
 * the active-sender gate and the admin mismatch warning.
 */
final class FromDomainGuard {

	/**
	 * Whether the verified sending domain covers this From-address.
	 */
	public static function allows( string $from_email, string $sending_domain ): bool {
		$from   = self::domain_of( $from_email );
		$domain = self::normalize( $sending_domain );

		if ( '' === $from || '' === $domain ) {
			return false;
		}

		return $from === $domain || str_ends_with( $from, '.' . $domain );
	}

	/**
	 * The normalized domain part of an email address, or '' if it has none.
	 */
	private static function domain_of( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return '';
		}
		return self::normalize( substr( $email, $at + 1 ) );
	}

	/**
	 * Lower-case, trim surrounding whitespace, and drop a trailing root dot so
	 * `Example.COM.` and `example.com` compare equal.
	 */
	private static function normalize( string $host ): string {
		return rtrim( strtolower( trim( $host ) ), '.' );
	}
}
