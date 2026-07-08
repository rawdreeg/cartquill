<?php
/**
 * The flow library screen: template cards must reflect installed state — an
 * installed template offers manage actions (edit, activate/deactivate), not a
 * second "Install".
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CartQuill\Admin\FlowLibraryPage;
use CartQuill\Flow\FlowInstaller;
use CartQuill\Flow\FlowLibrary;
use CartQuill\Persistence\InMemoryFlowRepository;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 ); // The page humanizes step delays.
}

final class FlowLibraryPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( InMemoryFlowRepository $flows ): string {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( '_n' )->alias( static fn( string $single, string $plural, int $number ) => 1 === $number ? $single : $plural );

		$library = new FlowLibrary();
		$page    = new FlowLibraryPage( $library, new FlowInstaller( $library, $flows ), $flows );

		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	public function test_every_template_offers_install_when_nothing_is_installed(): void {
		$html = $this->render( new InMemoryFlowRepository() );

		$templates = ( new FlowLibrary() )->templates();
		$this->assertSame(
			count( $templates ),
			substr_count( $html, 'value="cartquill_install_flow"' ),
			'one install form per template'
		);
	}

	public function test_installed_template_offers_manage_actions_instead_of_install(): void {
		$flows    = new InMemoryFlowRepository();
		$library  = new FlowLibrary();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$template = $library->templates()[0];
		$saved    = $flows->save( $template );

		$html = $this->render( $flows );

		$this->assertStringNotContainsString(
			'name="type" value="' . $template->type . '"',
			$html,
			'the installed template no longer offers Install'
		);
		$this->assertSame(
			count( $library->templates() ) - 1,
			substr_count( $html, 'value="cartquill_install_flow"' ),
			'the other templates still offer Install'
		);
		$this->assertStringContainsString( 'Installed', $html, 'the card says it is installed' );
		$this->assertSame(
			2,
			substr_count( $html, 'page=cartquill-flow-builder&flow=' . (int) $saved->id ),
			'the card (and the flows table) link to the builder for the installed flow'
		);
		$this->assertSame(
			2,
			substr_count( $html, '>Activate</button>' ),
			'the draft flow gets an Activate toggle in both the table and the card'
		);
	}

	public function test_installed_card_manages_the_most_recent_flow_of_its_type(): void {
		$flows   = new InMemoryFlowRepository();
		$library = new FlowLibrary();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$template = $library->templates()[0];
		$flows->save( $template );
		$second = $flows->save( $template );

		$html = $this->render( $flows );

		$this->assertSame(
			2,
			substr_count( $html, 'page=cartquill-flow-builder&flow=' . (int) $second->id ),
			'the card manages the newest copy (table row + card)'
		);
		$this->assertSame(
			1,
			substr_count( $html, 'page=cartquill-flow-builder&flow=1' ),
			'the older copy is only in the flows table'
		);
	}
}
