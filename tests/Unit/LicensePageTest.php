<?php
/**
 * The license screen: subscription tiers presented as cards driven by
 * Plans::entitlements(), with the current plan highlighted and the à la carte
 * keys demoted to a legacy foldout.
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
	private function render( array $stored_keys ): string {
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
		( new LicensePage( new OptionLicense() ) )->render();
		return (string) ob_get_clean();
	}

	public function test_renders_a_card_per_tier_with_its_entitlements(): void {
		$html = $this->render( array() );

		$this->assertSame( 3, substr_count( $html, 'class="cartquill-tier"' ), 'one card per tier, none highlighted' );
		$this->assertStringContainsString( '2000', $html, 'Starter action cap' );
		$this->assertStringContainsString( '25000', $html, 'Growth action cap' );
		$this->assertStringContainsString( '150000', $html, 'Agency action cap' );
		$this->assertStringNotContainsString( 'is-current', $html, 'no tier held → none highlighted' );

		foreach ( array( 'starter', 'growth', 'agency', 'ai', 'deliverability', 'pro' ) as $plan ) {
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
		foreach ( array( 'ai', 'deliverability', 'pro' ) as $plan ) {
			$this->assertStringContainsString( 'name="keys[' . $plan . ']"', $foldout, "{$plan} key lives in the foldout" );
		}
	}
}
