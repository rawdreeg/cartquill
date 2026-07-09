<?php
/**
 * The branded HTML email shell: it wraps rendered content + an unsubscribe
 * footer in a self-contained, inline-styled document without mangling either.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Engine\EmailTemplate;
use PHPUnit\Framework\TestCase;

final class EmailTemplateTest extends TestCase {

	public function test_wraps_content_and_footer_in_a_branded_document(): void {
		$html = ( new EmailTemplate() )->wrap(
			'<p>Come back to your cart</p>',
			'<a href="mailto:x@y.z">Unsubscribe</a>',
			'Acme Store'
		);

		// A self-contained HTML email document.
		$this->assertStringContainsString( '<!DOCTYPE html', $html );
		$this->assertStringContainsString( '<html', $html );
		$this->assertStringContainsString( '</html>', $html );

		// The rendered content and the footer pass through verbatim (not escaped).
		$this->assertStringContainsString( '<p>Come back to your cart</p>', $html );
		$this->assertStringContainsString( '<a href="mailto:x@y.z">Unsubscribe</a>', $html );

		// The store name brands the header.
		$this->assertStringContainsString( 'Acme Store', $html );

		// Inline-styled (email clients strip <style>/classes); table-based layout.
		$this->assertStringContainsString( 'style=', $html );
		$this->assertStringContainsString( '<table', $html );
	}

	public function test_escapes_the_store_name_but_never_the_content(): void {
		$html = ( new EmailTemplate() )->wrap(
			'<p>hi</p>',
			'<span>Unsubscribe</span>',
			'Bob & Co "Store" <script>'
		);

		// The store name is a plain string → HTML-escaped in the header.
		$this->assertStringContainsString( 'Bob &amp; Co &quot;Store&quot; &lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		// The content is trusted rendered HTML → passes through intact.
		$this->assertStringContainsString( '<p>hi</p>', $html );
	}

	public function test_falls_back_to_a_neutral_header_without_a_store_name(): void {
		$html = ( new EmailTemplate() )->wrap( '<p>hi</p>', '<span>Unsubscribe</span>', '' );

		$this->assertStringContainsString( '<p>hi</p>', $html );
		$this->assertStringContainsString( '<!DOCTYPE html', $html );
	}
}
