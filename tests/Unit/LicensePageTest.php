<?php
/**
 * The license screen in both of its modes.
 *
 * Managed (production): read-only, no key fields at all. Local (dev, behind
 * CARTQUILL_LOCAL_LICENSE): subscription tiers presented as cards driven by
 * Plans::entitlements(), with the current plan highlighted and the à la carte
 * keys demoted to a legacy foldout.
 *
 * The mode is passed explicitly rather than read from the environment — whether
 * the premium bootstrap happens to have been loaded by another test must not
 * decide what this screen renders.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Admin\LicensePage;
use CartQuill\Licensing\OptionLicense;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class LicensePageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $stored_keys
	 */
	private function render( array $stored_keys, bool $owns_plan = false ): string {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( $stored_keys );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ) => (string) $number );
		Functions\when( 'submit_button' )->justReturn( null );

		ob_start();
		( new LicensePage( new OptionLicense(), $owns_plan ) )->render();
		return (string) ob_get_clean();
	}

	public function test_renders_a_card_per_tier_with_its_entitlements(): void {
		$html = $this->render( array() );

		$this->assertSame( 3, substr_count( $html, 'class="cartquill-tier"' ), 'one card per tier, none highlighted' );
		$this->assertStringContainsString( '2000', $html, 'Starter action cap' );
		$this->assertStringContainsString( '25000', $html, 'Growth action cap' );
		$this->assertStringContainsString( '150000', $html, 'Agency action cap' );
		$this->assertStringNotContainsString( 'is-current', $html, 'no tier held → none highlighted' );

		foreach ( array( 'starter', 'growth', 'agency', 'ai', 'pro' ) as $plan ) {
			$this->assertStringContainsString( 'name="keys[' . $plan . ']"', $html, "a key input for {$plan}" );
		}
	}

	public function test_highlights_the_held_tier_and_masks_its_key(): void {
		$html = $this->render( array( 'growth' => 'gw-key-123' ) );

		$this->assertSame( 1, substr_count( $html, 'is-current' ), 'exactly the held tier is highlighted' );
		$this->assertStringContainsString( 'Current plan', $html );
		$this->assertSame( 1, substr_count( $html, 'value="••••••••"' ), 'the stored key renders masked, never in the clear' );
		$this->assertStringNotContainsString( 'gw-key-123', $html );
	}

	public function test_demotes_a_la_carte_keys_to_a_legacy_foldout(): void {
		$html = $this->render( array() );

		$this->assertStringContainsString( '<details class="cartquill-legacy"', $html );
		$foldout = (string) strstr( $html, '<details' );
		foreach ( array( 'ai', 'pro' ) as $plan ) {
			$this->assertStringContainsString( 'name="keys[' . $plan . ']"', $foldout, "{$plan} key lives in the foldout" );
		}
	}

	public function test_local_mode_says_so(): void {
		$this->assertStringContainsString( 'CARTQUILL_LOCAL_LICENSE', $this->render( array() ) );
	}

	/**
	 * The bypass this screen used to be. OptionLicense accepts any non-empty
	 * string as a held plan, so a key field on a production install would unlock
	 * the paid add-ons for anyone with a copy of the premium zip.
	 */
	public function test_managed_mode_offers_no_way_to_enter_a_key(): void {
		$html = $this->render( array(), true );

		$this->assertStringNotContainsString( 'name="keys[', $html, 'no key input, for any plan' );
		$this->assertStringNotContainsString( '<form', $html, 'nothing to submit' );
		$this->assertStringNotContainsString( 'cartquill-legacy', $html, 'not even the legacy foldout' );
		$this->assertStringNotContainsString( 'CARTQUILL_LOCAL_LICENSE', $html );
	}

	public function test_managed_mode_ignores_a_stored_key_when_reporting_the_plan(): void {
		// A key in the option table is exactly what a bypass would rely on. The
		// filter chain is stubbed out here (apply_filters returns the input), so
		// this asserts the *page* never treats a stored key as a subscription.
		$html = $this->render( array( 'growth' => 'gw-key-123' ), true );

		$this->assertStringNotContainsString( 'gw-key-123', $html );
		$this->assertStringNotContainsString( '••••••••', $html, 'no key is echoed, masked or otherwise' );
	}

	public function test_managed_mode_still_shows_the_tiers_and_a_way_to_subscribe(): void {
		$html = $this->render( array(), true );

		$this->assertSame( 3, substr_count( $html, 'cartquill-tier"' ), 'the tier cards still explain what each plan buys' );
		$this->assertStringContainsString( 'Manage your subscription', $html );
		$this->assertStringContainsString( 'No active subscription', $html );
	}
}
