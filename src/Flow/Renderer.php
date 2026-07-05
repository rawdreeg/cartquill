<?php
/**
 * Renders step templates into concrete subject/body text.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Minimal mustache-style placeholder substitution: {{ key }} -> value.
 *
 * Kept intentionally small for the spine; richer merge tags arrive with the
 * flow-editor slice. Unknown placeholders render as empty strings so a stray
 * tag never leaks raw markup to a customer.
 */
final class Renderer {

	/**
	 * Render into an HTML context: substituted values are escaped per placeholder
	 * — URLs (a `url` key or one ending in `_url`) through esc_url, everything
	 * else HTML-escaped — so a merge value carrying markup can never inject it.
	 *
	 * @param array<string, string> $context
	 */
	public function render( string $template, array $context ): string {
		return $this->substitute( $template, $context, true );
	}

	/**
	 * Render into a plain-text context (e.g. an email subject): values are
	 * substituted raw, since HTML-escaping would surface literal entities.
	 *
	 * @param array<string, string> $context
	 */
	public function render_text( string $template, array $context ): string {
		return $this->substitute( $template, $context, false );
	}

	/**
	 * @param array<string, string> $context
	 */
	private function substitute( string $template, array $context, bool $escape_html ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
			static function ( array $m ) use ( $context, $escape_html ): string {
				$key   = $m[1];
				$value = (string) ( $context[ $key ] ?? '' );
				if ( '' === $value ) {
					return '';
				}
				if ( ! $escape_html ) {
					return $value;
				}
				if ( 'url' === $key || str_ends_with( $key, '_url' ) ) {
					return function_exists( 'esc_url' ) ? \esc_url( $value ) : $value;
				}
				return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
			},
			$template
		);
	}
}
