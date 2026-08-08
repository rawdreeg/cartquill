<?php
/**
 * What the builder needs to know to offer a trigger: its identity, the context it
 * captures, and the capability it requires.
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
 * Availability} key an extension tags its own triggers with (null for the triggers
 * CartQuill ships, which are always offered).
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
