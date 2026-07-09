<?php
/**
 * The domain-health monitor re-polls Resend for the sending domain's current
 * verification state, so a domain that drifts to unverified auto-falls back to
 * wp_mail (the active-sender gate reads is_domain_verified()).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Deliverability\DomainHealthMonitor;
use CartQuill\Deliverability\DomainStatus;
use CartQuill\Deliverability\EspSettings;
use CartQuill\Security\Crypto;
use CartQuill\Tests\Fake\StubResendClient;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DomainHealthMonitorTest extends TestCase {

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

	/** A provisioned, verified domain. */
	private function provisioned_esp(): EspSettings {
		$esp = $this->esp();
		$esp->set_api_key( 're_test' );
		$esp->set_domain( 'mail.acme.test' );
		$esp->set_domain_id( 'dom_1' );
		$esp->set_domain_verified( true );
		$esp->set_domain_state( 'verified' );
		return $esp;
	}

	public function test_refresh_keeps_a_still_verified_domain_verified(): void {
		$esp    = $this->provisioned_esp();
		$client = new StubResendClient();
		$client->will_return_domain_status( new DomainStatus( 'mail.acme.test', true, 'verified', array(), 'dom_1' ) );

		( new DomainHealthMonitor( $esp, $client ) )->refresh();

		$this->assertTrue( $esp->is_domain_verified() );
		$this->assertSame( 'verified', $esp->domain_state() );
	}

	public function test_refresh_flips_a_drifted_domain_to_unverified(): void {
		$esp    = $this->provisioned_esp();
		$client = new StubResendClient();
		// Resend now reports the domain is no longer verified (DNS changed/removed).
		$client->will_return_domain_status( new DomainStatus( 'mail.acme.test', false, 'failed', array(), 'dom_1' ) );

		( new DomainHealthMonitor( $esp, $client ) )->refresh();

		$this->assertFalse( $esp->is_domain_verified(), 'a drifted domain flips off so sending falls back to wp_mail' );
		$this->assertSame( 'failed', $esp->domain_state() );
	}

	public function test_refresh_is_a_noop_without_a_provisioned_domain(): void {
		$esp = $this->esp();
		$esp->set_api_key( 're_test' );
		$esp->set_domain_verified( true ); // stale flag, but no domain_id to poll
		$client = new StubResendClient();
		// If refresh polled, the stub's default status (unverified) would flip it.
		$client->will_return_domain_status( new DomainStatus( '', false, 'pending', array(), '' ) );

		( new DomainHealthMonitor( $esp, $client ) )->refresh();

		$this->assertTrue( $esp->is_domain_verified(), 'nothing provisioned → no poll, no change' );
	}

	public function test_refresh_unverifies_when_resend_reports_the_domain_is_gone(): void {
		$esp    = $this->provisioned_esp();
		$client = new StubResendClient();
		$client->fail( 404 ); // the domain was deleted in Resend (or the account changed)

		( new DomainHealthMonitor( $esp, $client ) )->refresh();

		$this->assertFalse( $esp->is_domain_verified(), 'a domain gone from Resend falls back to wp_mail' );
		$this->assertSame( 'not_found', $esp->domain_state() );
	}

	public function test_refresh_leaves_state_unchanged_on_a_transient_api_error(): void {
		$esp    = $this->provisioned_esp();
		$client = new StubResendClient();
		$client->fail(); // Resend unreachable

		( new DomainHealthMonitor( $esp, $client ) )->refresh();

		$this->assertTrue( $esp->is_domain_verified(), 'a transient API error must not flip a good domain off' );
		$this->assertSame( 'verified', $esp->domain_state() );
	}
}
