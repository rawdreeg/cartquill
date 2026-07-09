<?php
/**
 * The Deliverability add-on's active-sender gate: Resend only goes live when the
 * license, key, verified domain, AND the From-address all line up.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Compliance\ArraySuppressionList;
use CartQuill\Deliverability\DeliverabilityAddon;
use CartQuill\Deliverability\EspSettings;
use CartQuill\Deliverability\ResendSender;
use CartQuill\Licensing\ArrayLicense;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\InMemoryMessageRepository;
use CartQuill\Security\Crypto;
use CartQuill\Sender\SenderRegistry;
use CartQuill\Settings\ArraySettings;
use PHPUnit\Framework\TestCase;

final class DeliverabilityAddonTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Back EspSettings' wp_option storage with an in-memory array. Both
		// closures capture $options by reference so reads see prior writes.
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

	/** Identity crypto: the encryption seam is exercised elsewhere. */
	private function crypto(): Crypto {
		return new class() implements Crypto {
			public function encrypt( string $plaintext ): string {
				return $plaintext;
			}
			public function decrypt( string $ciphertext ): ?string {
				return $ciphertext;
			}
		};
	}

	/**
	 * A fully provisioned EspSettings: key present + domain verified.
	 */
	private function esp( string $domain = 'example.com' ): EspSettings {
		$esp = new EspSettings( $this->crypto() );
		$esp->set_api_key( 're_test' );
		$esp->set_domain( $domain );
		$esp->set_domain_verified( true );
		return $esp;
	}

	private function addon( EspSettings $esp, ArrayLicense $license, string $from_email ): DeliverabilityAddon {
		return new DeliverabilityAddon(
			$esp,
			$license,
			new InMemoryMessageRepository(),
			new ArraySuppressionList(),
			new ArraySettings( 'Acme', $from_email )
		);
	}

	private function licensed(): ArrayLicense {
		return new ArrayLicense( array( Plans::DELIVERABILITY ) );
	}

	public function test_makes_resend_active_when_the_from_domain_matches_the_verified_domain(): void {
		$addon = $this->addon( $this->esp( 'example.com' ), $this->licensed(), 'hello@example.com' );

		$this->assertSame( 'resend', $addon->pick_active_sender( 'wp_mail' ) );
	}

	public function test_makes_resend_active_when_the_from_is_a_subdomain_of_the_verified_domain(): void {
		$addon = $this->addon( $this->esp( 'example.com' ), $this->licensed(), 'hello@mail.example.com' );

		$this->assertSame( 'resend', $addon->pick_active_sender( 'wp_mail' ) );
	}

	public function test_stays_on_wp_mail_when_the_from_domain_does_not_match_the_verified_domain(): void {
		// The functional bug: verified example.com, but From still on gmail → 554.
		$addon = $this->addon( $this->esp( 'example.com' ), $this->licensed(), 'owner@gmail.com' );

		$this->assertSame( 'wp_mail', $addon->pick_active_sender( 'wp_mail' ) );
	}

	public function test_stays_on_wp_mail_without_a_license(): void {
		$addon = $this->addon( $this->esp( 'example.com' ), new ArrayLicense(), 'hello@example.com' );

		$this->assertSame( 'wp_mail', $addon->pick_active_sender( 'wp_mail' ) );
	}

	public function test_stays_on_wp_mail_when_the_domain_is_unverified(): void {
		$esp = new EspSettings( $this->crypto() );
		$esp->set_api_key( 're_test' );
		$esp->set_domain( 'example.com' ); // not verified

		$addon = $this->addon( $esp, $this->licensed(), 'hello@example.com' );

		$this->assertSame( 'wp_mail', $addon->pick_active_sender( 'wp_mail' ) );
	}

	public function test_registers_the_resend_sender_only_when_the_from_domain_matches(): void {
		$matching = new SenderRegistry( 'wp_mail' );
		$this->addon( $this->esp( 'example.com' ), $this->licensed(), 'hello@example.com' )
			->register_sender( $matching, $this->licensed() );
		$this->assertInstanceOf( ResendSender::class, $matching->get( 'resend' ) );

		$mismatch = new SenderRegistry( 'wp_mail' );
		$this->addon( $this->esp( 'example.com' ), $this->licensed(), 'owner@gmail.com' )
			->register_sender( $mismatch, $this->licensed() );
		$this->assertNull( $mismatch->get( 'resend' ), 'no Resend sender when From would bounce' );
	}

	public function test_renders_the_mismatch_notice_naming_the_from_and_verified_domain(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$addon = $this->addon( $this->esp( 'example.com' ), $this->licensed(), 'owner@gmail.com' );

		ob_start();
		$addon->render_from_domain_mismatch_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'owner@gmail.com', $html, 'names the offending From address' );
		$this->assertStringContainsString( 'example.com', $html, 'names the verified sending domain' );
	}

	public function test_mismatch_notice_is_hidden_from_users_without_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$addon = $this->addon( $this->esp( 'example.com' ), $this->licensed(), 'owner@gmail.com' );

		ob_start();
		$addon->render_from_domain_mismatch_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'no capability, no output' );
	}

	public function test_hooks_the_mismatch_notice_only_when_the_from_domain_does_not_match(): void {
		// register_surfaces only touches add_action on this path (key decryptable,
		// no webhook secret), so recording add_action captures the wiring decision.
		$hooked = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback = null ) use ( &$hooked ): bool {
				$hooked[] = array( $hook, $callback );
				return true;
			}
		);

		$this->addon( $this->esp( 'example.com' ), $this->licensed(), 'owner@gmail.com' )->register_surfaces();
		$this->assertTrue( $this->hooks_notice( $hooked, 'render_from_domain_mismatch_notice' ), 'mismatch warns the operator' );

		$hooked = array();
		$this->addon( $this->esp( 'example.com' ), $this->licensed(), 'hello@example.com' )->register_surfaces();
		$this->assertFalse( $this->hooks_notice( $hooked, 'render_from_domain_mismatch_notice' ), 'no false-positive warning when From matches' );
	}

	public function test_delivery_tracking_is_ready_only_when_fully_wired(): void {
		$esp = $this->esp( 'example.com' ); // licensed key + verified domain
		$esp->set_webhook_secret( 'whsec_live' );
		$addon = $this->addon( $esp, $this->licensed(), 'hello@example.com' );

		$this->assertTrue( $addon->delivery_tracking_ready( false ) );
	}

	public function test_delivery_tracking_is_not_ready_without_a_webhook_secret(): void {
		// No secret → the endpoint never registers → no events ever arrive.
		$addon = $this->addon( $this->esp( 'example.com' ), $this->licensed(), 'hello@example.com' );

		$this->assertFalse( $addon->delivery_tracking_ready( false ) );
	}

	public function test_delivery_tracking_is_not_ready_without_a_verified_domain_or_license(): void {
		$unverified = new EspSettings( $this->crypto() );
		$unverified->set_api_key( 're_test' );
		$unverified->set_domain( 'example.com' ); // not verified
		$unverified->set_webhook_secret( 'whsec_live' );
		$this->assertFalse(
			$this->addon( $unverified, $this->licensed(), 'hello@example.com' )->delivery_tracking_ready( false )
		);

		$wired = $this->esp( 'example.com' );
		$wired->set_webhook_secret( 'whsec_live' );
		$this->assertFalse(
			$this->addon( $wired, new ArrayLicense(), 'hello@example.com' )->delivery_tracking_ready( false ),
			'unlicensed is never tracking-ready'
		);
	}

	public function test_warns_when_the_webhook_secret_cannot_be_decrypted(): void {
		$hooked = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback = null ) use ( &$hooked ): bool {
				$hooked[] = array( $hook, $callback );
				return true;
			}
		);

		// A stored-but-undecryptable secret (the install key was lost). encrypt is
		// identity so the stored value round-trips to storage; decrypt fails.
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

		$this->addon( $esp, $this->licensed(), 'hello@example.com' )->register_surfaces();

		$this->assertTrue(
			$this->hooks_notice( $hooked, 'render_undecryptable_secret_notice' ),
			'a broken webhook secret is surfaced rather than silently killing ingestion'
		);
	}

	/**
	 * @param array<int, array{0: string, 1: mixed}> $hooked Recorded add_action calls.
	 */
	private function hooks_notice( array $hooked, string $method ): bool {
		foreach ( $hooked as $call ) {
			$callback = $call[1];
			if ( 'admin_notices' === $call[0]
				&& is_array( $callback )
				&& isset( $callback[1] )
				&& $method === $callback[1] ) {
				return true;
			}
		}
		return false;
	}
}
