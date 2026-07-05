<?php
/**
 * A stable per-install secret key for signing and encryption.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Deriving signer/crypto keys from wp_salt() means a salt rotation invalidates
 * every in-flight unsubscribe link and makes the stored ESP key undecryptable.
 * This persists a random key once (non-autoloaded) so both survive salt
 * rotation; it changes only if the option is deleted.
 */
final class InstallKey {

	public const OPTION = 'cartquill_secret_key';

	public static function get(): string {
		$key = \get_option( self::OPTION, '' );
		if ( ! is_string( $key ) || '' === $key ) {
			$key = bin2hex( random_bytes( 32 ) );
			\update_option( self::OPTION, $key, false );
		}
		return $key;
	}
}
