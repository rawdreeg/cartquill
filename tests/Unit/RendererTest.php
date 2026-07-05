<?php
/**
 * Template placeholder substitution escapes merge values.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Flow\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	public function test_substitutes_known_placeholders(): void {
		$out = ( new Renderer() )->render( 'Hi {{ name }}!', array( 'name' => 'Jo' ) );
		$this->assertSame( 'Hi Jo!', $out );
	}

	public function test_unknown_placeholder_renders_empty(): void {
		$this->assertSame( 'X', ( new Renderer() )->render( 'X{{ missing }}', array() ) );
	}

	public function test_a_hostile_merge_value_is_html_escaped_in_the_body(): void {
		$out = ( new Renderer() )->render( 'Hi {{ name }}', array( 'name' => '<script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>', $out, 'markup in a merge value cannot reach the email' );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
	}

	public function test_text_render_does_not_html_escape_the_subject(): void {
		$out = ( new Renderer() )->render_text( '{{ store }} sale', array( 'store' => "Ben & Jerry's" ) );

		$this->assertSame( "Ben & Jerry's sale", $out, 'a plain-text subject keeps ampersands and quotes literal' );
	}
}
