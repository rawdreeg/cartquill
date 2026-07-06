<?php
/**
 * Encrypts/decrypts a connection's credential map for storage.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Security\Crypto;

/**
 * Encodes a credential map to an encrypted string for the `credentials` column
 * and back, honoring the locked rule that credentials never touch the database
 * in the clear. JSON-serialised, then encrypted with the injected {@see Crypto}.
 * A value that fails to decrypt (rotated site salt, tampering) yields an empty
 * map, so a connection degrades to "not configured" rather than exposing garbage.
 */
final class EncryptedCredentials {

	public function __construct( private readonly Crypto $crypto ) {}

	/**
	 * @param array<string, mixed> $credentials
	 *
	 * @return string|null Ciphertext, or null for an empty map.
	 */
	public function encode( array $credentials ): ?string {
		if ( array() === $credentials ) {
			return null;
		}
		return $this->crypto->encrypt( (string) \wp_json_encode( $credentials ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function decode( ?string $raw ): array {
		if ( null === $raw || '' === $raw ) {
			return array();
		}
		$json = $this->crypto->decrypt( $raw );
		if ( null === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}
}
