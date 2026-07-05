<?php
/**
 * libsodium-backed Crypto: authenticated symmetric encryption.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Encrypts with `sodium_crypto_secretbox` (XSalsa20-Poly1305): authenticated, so
 * tampered ciphertext fails to decrypt rather than yielding garbage. A fresh
 * random nonce is prepended to every payload and the whole thing base64-encoded
 * for storage in a wp_option. The key is derived from a WordPress secret at the
 * composition root, so nothing secret is hard-coded here.
 */
final class SodiumCrypto implements Crypto {

	private const VERSION = 'v1';

	/** @var string 32-byte secretbox key. */
	private readonly string $key;

	/**
	 * @param string $key Raw key material; hashed to the exact key length so any
	 *                    WordPress salt string can be passed in.
	 */
	public function __construct( string $key ) {
		$this->key = sodium_crypto_generichash( $key, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public function encrypt( string $plaintext ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );

		return self::VERSION . ':' . base64_encode( $nonce . $cipher );
	}

	public function decrypt( string $ciphertext ): ?string {
		$parts = explode( ':', $ciphertext, 2 );
		if ( 2 !== count( $parts ) || self::VERSION !== $parts[0] ) {
			return null;
		}

		$raw = base64_decode( $parts[1], true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );

		return false === $plaintext ? null : $plaintext;
	}
}
