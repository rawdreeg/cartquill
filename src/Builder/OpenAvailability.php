<?php
/**
 * The default availability: everything the catalog knows about is offered.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * CartQuill offers every trigger, action, and condition it ships. This is the
 * implementation the plugin uses; an extension that contributes descriptors of
 * its own can replace it through the `cartquill_builder_availability` filter to
 * describe when *its* extras are ready.
 */
final class OpenAvailability implements Availability {

	public function allows( ?string $capability ): bool {
		return true;
	}

	public function allows_gates(): bool {
		return true;
	}
}
