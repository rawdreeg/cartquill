<?php
/**
 * Builds a live BuilderCatalog, folding in any add-on contributions.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Licensing\License;
use CartQuill\Persistence\ConnectionStore;

/**
 * The composition seam between the core defaults and the add-ons: it applies the
 * {@see BuilderCatalog::FILTER_ACTIONS} / {@see BuilderCatalog::FILTER_TRIGGERS}
 * filters over the core descriptors so an active add-on can replace the paid
 * fallbacks with authoritative descriptors and add its triggers, then hands the
 * merged lists to a catalog. Kept separate from {@see BuilderCatalog} so the catalog
 * itself stays a pure, WordPress-free value computation.
 */
final class CatalogFactory {

	public static function create( License $license, ConnectionStore $connections ): BuilderCatalog {
		// Both hook names are `cartquill_`-prefixed string constants on
		// BuilderCatalog; PHPCS only reads literals, so it cannot see the prefix
		// through the class constant and reports a dynamic hook name.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		/** @var list<ActionDescriptor> $actions */
		$actions = (array) \apply_filters( BuilderCatalog::FILTER_ACTIONS, CoreActionDescriptors::all() );
		/** @var list<TriggerDescriptor> $triggers */
		$triggers = (array) \apply_filters( BuilderCatalog::FILTER_TRIGGERS, CoreTriggers::all() );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

		return new BuilderCatalog( $license, $connections, $actions, $triggers );
	}
}
