<?php
/**
 * EspSettings persists the sending domain's DNS records + state durably, so they
 * survive the user leaving to their registrar, and clears them when the domain
 * changes.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Deliverability\EspSettings;
use CartQuill\Security\Crypto;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class EspSettingsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$options = array();
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$options ): bool {
				$options[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function esp(): EspSettings {
		return new EspSettings(
			new class() implements Crypto {
				public function encrypt( string $plaintext ): string {
					return $plaintext;
				}
				public function decrypt( string $ciphertext ): ?string {
					return $ciphertext;
				}
			}
		);
	}

	private function records(): array {
		return array(
			array( 'purpose' => 'MX', 'type' => 'MX', 'name' => 'send', 'value' => 'feedback-smtp.resend.com', 'priority' => '10', 'ttl' => 'Auto', 'status' => 'pending' ),
			array( 'purpose' => 'SPF', 'type' => 'TXT', 'name' => 'send', 'value' => 'v=spf1 include:amazonses.com ~all', 'priority' => '', 'ttl' => 'Auto', 'status' => 'pending' ),
		);
	}

	public function test_domain_records_round_trip(): void {
		$esp = $this->esp();
		$esp->set_domain( 'mail.acme.test' );
		$esp->set_domain_records( $this->records() );

		$this->assertSame( $this->records(), $esp->domain_records() );
	}

	public function test_domain_records_default_to_empty(): void {
		$this->assertSame( array(), $this->esp()->domain_records() );
	}

	public function test_domain_state_round_trips_and_defaults_empty(): void {
		$esp = $this->esp();
		$this->assertSame( '', $esp->domain_state() );

		$esp->set_domain_state( 'pending' );
		$this->assertSame( 'pending', $esp->domain_state() );
	}

	public function test_changing_the_domain_clears_persisted_records_and_state(): void {
		$esp = $this->esp();
		$esp->set_domain( 'mail.acme.test' );
		$esp->set_domain_id( 'dom_1' );
		$esp->set_domain_verified( true );
		$esp->set_domain_records( $this->records() );
		$esp->set_domain_state( 'verified' );

		$esp->set_domain( 'mail.other.test' ); // a different sending domain

		$this->assertSame( array(), $esp->domain_records(), 'stale records must not show for a new domain' );
		$this->assertSame( '', $esp->domain_state() );
		$this->assertSame( '', $esp->domain_id() );
		$this->assertFalse( $esp->is_domain_verified() );
	}

	public function test_reports_an_undecryptable_webhook_secret(): void {
		// A secret was stored, then the install key was lost so decrypt now fails.
		$esp = new EspSettings(
			new class() implements Crypto {
				public function encrypt( string $plaintext ): string {
					return $plaintext;
				}
				public function decrypt( string $ciphertext ): ?string {
					return null;
				}
			}
		);
		$esp->set_webhook_secret( 'whsec_lost' );

		$this->assertTrue( $esp->has_undecryptable_secret() );
		$this->assertSame( '', $esp->webhook_secret(), 'reads as empty so ingestion degrades safely' );
	}

	public function test_a_decryptable_webhook_secret_is_not_flagged(): void {
		$esp = $this->esp();
		$esp->set_webhook_secret( 'whsec_ok' );

		$this->assertFalse( $esp->has_undecryptable_secret() );
	}

	public function test_no_webhook_secret_is_not_undecryptable(): void {
		$this->assertFalse( $this->esp()->has_undecryptable_secret() );
	}

	public function test_re_saving_the_same_domain_preserves_records_and_state(): void {
		$esp = $this->esp();
		$esp->set_domain( 'mail.acme.test' );
		$esp->set_domain_records( $this->records() );
		$esp->set_domain_state( 'verified' );
		$esp->set_domain_verified( true );

		$esp->set_domain( 'mail.acme.test' ); // unchanged

		$this->assertSame( $this->records(), $esp->domain_records() );
		$this->assertSame( 'verified', $esp->domain_state() );
		$this->assertTrue( $esp->is_domain_verified() );
	}
}
