<?php
/**
 * An action that describes its editable config fields for the flow builder.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The additive, opt-in seam that lets the flow builder render a form for an
 * action's per-step config without hardcoding field knowledge in the UI.
 *
 * It sits beside {@see \CartQuill\Action\ActionInterface} rather than extending
 * it, so the engine's action contract is untouched. An action's {@see
 * self::config_fields()} declares the same keys its `execute()` reads from the
 * step config, so the descriptor and the runtime cannot drift.
 *
 * A field is a plain, JSON-serializable map the REST layer passes straight to the
 * builder:
 *
 *     array{
 *       key: string,                 // the step-config key this control writes
 *       label: string,               // human label
 *       type: string,                // text | textarea | html | number | select | list
 *       default?: mixed,             // starting value
 *       required?: bool,             // must be non-empty to save
 *       help?: string,               // one-line hint
 *       supports_merge_tags?: bool,  // whether {{ context }} tags render here
 *       options?: list<string>,      // choices for a select
 *     }
 */
interface DescribesConfig {

	/**
	 * The editable config fields for this action, in display order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function config_fields(): array;
}
