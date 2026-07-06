<?php
/**
 * Connection credentials are encrypted at rest — no cleartext ever persisted.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Persistence\EncryptedCredentials;
use CartQuill\Security\SodiumCrypto;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class EncryptedCredentialsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private EncryptedCredentials $cipher;

	protected function setUp(): void {
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $v ) => json_encode( $v ) );
		$this->cipher = new EncryptedCredentials( new SodiumCrypto( 'test-install-key' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	public function test_encodes_credentials_without_leaking_cleartext(): void {
		$secret = 'https://hooks.slack.com/services/T00/B00/XYZsecret123';

		$encoded = $this->cipher->encode( array( 'webhook_url' => $secret ) );

		$this->assertNotNull( $encoded );
		$this->assertStringNotContainsString( $secret, $encoded, 'the secret never appears in the stored value' );
		$this->assertStringNotContainsString( 'hooks.slack.com', $encoded );
		$this->assertStringNotContainsString( 'webhook_url', $encoded );
	}

	public function test_round_trips_the_credential_map(): void {
		$credentials = array(
			'webhook_url' => 'https://hooks.slack.com/services/secret',
			'channel'     => '#orders',
		);

		$decoded = $this->cipher->decode( $this->cipher->encode( $credentials ) );

		$this->assertSame( $credentials, $decoded );
	}

	public function test_empty_map_encodes_to_null(): void {
		$this->assertNull( $this->cipher->encode( array() ) );
		$this->assertSame( array(), $this->cipher->decode( null ) );
	}

	public function test_undecryptable_value_degrades_to_empty(): void {
		// A tampered/garbage column (e.g. the site salt rotated) yields no
		// credentials rather than throwing or exposing garbage.
		$this->assertSame( array(), $this->cipher->decode( 'not-a-valid-ciphertext' ) );
	}
}
