<?php
/**
 * The default set of action descriptors the builder catalog is seeded with.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Action\EmailAction;

/**
 * The action descriptors CartQuill ships. The `email` action is authoritative,
 * sourced from {@see EmailAction::config_fields()} so the builder edits exactly the
 * config keys the action reads at runtime.
 *
 * An extension adds its own channels by appending descriptors through the
 * {@see BuilderCatalog::FILTER_ACTIONS} filter, so its metadata lives with the code
 * that implements it and this module never has to know about it.
 */
final class CoreActionDescriptors {

	/**
	 * @return list<ActionDescriptor>
	 */
	public static function all(): array {
		return array(
			new ActionDescriptor( EmailAction::TYPE, 'Send email', null, null, true, EmailAction::config_fields() ),
		);
	}
}
