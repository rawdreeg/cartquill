<?php
/**
 * Shared builder for a single config-field descriptor.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A tiny helper the actions (and the core fallback descriptors) use to emit a
 * config field in one consistent, fully-populated shape, so every action's fields
 * carry the same keys regardless of which class declared them.
 */
trait ConfigFields {

	/**
	 * Build one field descriptor with sensible defaults for the omitted keys.
	 *
	 * @param array{default?: mixed, required?: bool, help?: string, merge?: bool, options?: list<string>} $opts
	 * @return array<string, mixed>
	 */
	protected static function config_field( string $key, string $label, string $type, array $opts = array() ): array {
		$field = array(
			'key'                 => $key,
			'label'               => $label,
			'type'                => $type,
			'default'             => $opts['default'] ?? '',
			'required'            => $opts['required'] ?? false,
			'help'                => $opts['help'] ?? '',
			'supports_merge_tags' => $opts['merge'] ?? false,
		);
		if ( isset( $opts['options'] ) ) {
			$field['options'] = $opts['options'];
		}
		return $field;
	}
}
