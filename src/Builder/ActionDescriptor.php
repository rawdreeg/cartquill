<?php
/**
 * What the builder needs to know to offer an action: its identity, the capability and
 * connection it requires, and its editable config fields.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A value object describing one step action for the builder. It carries the
 * static facts (what the action is, which capability + connection it needs, what
 * fields it edits); the {@see BuilderCatalog} computes the live availability from
 * the {@see Availability} seam and connection status when it serializes.
 *
 * `capability` is an opaque key an extension tags its own descriptors with, so its
 * {@see Availability} can answer for them; it is null for everything CartQuill
 * ships, which is always offered. `service` is the connection key that must be
 * connected before the action can run (null when it needs no external connection).
 */
final class ActionDescriptor {

	/**
	 * @param string                     $type            Stable action key (e.g. "slack_post").
	 * @param string                     $label           Human label for the picker.
	 * @param string|null                $service         Connection key required, or null.
	 * @param string|null                $capability      Extension capability key, or null for core.
	 * @param bool                       $customer_facing Whether it reaches the customer directly.
	 * @param list<array<string, mixed>> $fields          Editable config field descriptors.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $label,
		public readonly ?string $service,
		public readonly ?string $capability,
		public readonly bool $customer_facing,
		public readonly array $fields,
	) {}
}
