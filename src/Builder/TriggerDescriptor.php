<?php
/**
 * What the builder needs to know to offer a trigger: its identity, the context it
 * captures, and the plan it requires.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A value object describing one flow trigger for the builder. `context_keys` are
 * the variables the trigger captures into the enrollment context (offered to the
 * merge-tag picker and value gates); `capability` is the {@see
 * \CartQuill\Licensing\Plans} constant a store must hold for the trigger to fire
 * (null for the core email triggers, which every tier ships).
 */
final class TriggerDescriptor {

	/**
	 * @param list<string> $context_keys Enrollment-context keys the trigger captures.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $label,
		public readonly string $description,
		public readonly array $context_keys,
		public readonly ?string $capability = null,
	) {}
}
