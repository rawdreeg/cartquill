<?php
/**
 * Renders step templates into concrete subject/body text.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Flow;

/**
 * Minimal mustache-style placeholder substitution: {{ key }} -> value.
 *
 * Kept intentionally small for the spine; richer merge tags arrive with the
 * flow-editor slice. Unknown placeholders render as empty strings so a stray
 * tag never leaks raw markup to a customer.
 */
final class Renderer {

	/**
	 * @param array<string, string> $context
	 */
	public function render( string $template, array $context ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
			static function ( array $m ) use ( $context ): string {
				return $context[ $m[1] ] ?? '';
			},
			$template
		);
	}
}
