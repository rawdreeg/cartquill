<?php
/**
 * Symmetric encryption seam for credentials at rest.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The single seam for encrypting secrets (the customer's ESP API key) before
 * they touch the database. `decrypt()` returns null on any failure — wrong key,
 * tampering, corruption — so callers never operate on forged plaintext.
 */
interface Crypto {

	/**
	 * Encrypt plaintext into a self-describing, storable string.
	 */
	public function encrypt( string $plaintext ): string;

	/**
	 * Decrypt a value produced by {@see encrypt()}. Null if it cannot be
	 * authenticated (wrong key, tampered, corrupt).
	 */
	public function decrypt( string $ciphertext ): ?string;
}
