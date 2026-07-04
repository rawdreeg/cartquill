<?php
/**
 * Credential encryption at rest: round-trip, opacity, and tamper/wrong-key
 * rejection.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Security\SodiumCrypto;
use PHPUnit\Framework\TestCase;

final class SodiumCryptoTest extends TestCase {

	public function test_round_trips_plaintext(): void {
		$crypto = new SodiumCrypto( 'a-wordpress-salt' );

		$this->assertSame( 're_secret_api_key', $crypto->decrypt( $crypto->encrypt( 're_secret_api_key' ) ) );
	}

	public function test_ciphertext_does_not_leak_plaintext(): void {
		$crypto     = new SodiumCrypto( 'a-wordpress-salt' );
		$ciphertext = $crypto->encrypt( 're_secret_api_key' );

		$this->assertStringNotContainsString( 're_secret_api_key', $ciphertext, 'stored value is opaque' );
	}

	public function test_each_encryption_uses_a_fresh_nonce(): void {
		$crypto = new SodiumCrypto( 'a-wordpress-salt' );

		$this->assertNotSame(
			$crypto->encrypt( 're_secret_api_key' ),
			$crypto->encrypt( 're_secret_api_key' ),
			'identical plaintext encrypts to different ciphertext'
		);
	}

	public function test_wrong_key_cannot_decrypt(): void {
		$ciphertext = ( new SodiumCrypto( 'the-real-salt' ) )->encrypt( 're_secret_api_key' );

		$this->assertNull( ( new SodiumCrypto( 'a-different-salt' ) )->decrypt( $ciphertext ) );
	}

	public function test_tampered_ciphertext_is_rejected(): void {
		$crypto     = new SodiumCrypto( 'a-wordpress-salt' );
		$ciphertext = $crypto->encrypt( 're_secret_api_key' );
		$tampered   = substr( $ciphertext, 0, -2 ) . ( 'AA' === substr( $ciphertext, -2 ) ? 'BB' : 'AA' );

		$this->assertNull( $crypto->decrypt( $tampered ) );
	}

	public function test_garbage_input_returns_null(): void {
		$crypto = new SodiumCrypto( 'a-wordpress-salt' );

		$this->assertNull( $crypto->decrypt( 'not-a-ciphertext' ) );
		$this->assertNull( $crypto->decrypt( '' ) );
		$this->assertNull( $crypto->decrypt( 'v1:@@@notbase64@@@' ) );
	}
}
