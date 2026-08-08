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

use CartQuill\Persistence\ConnectionStore;

/**
 * The composition seam between the core defaults and any extensions: it applies
 * the {@see BuilderCatalog::FILTER_ACTIONS} / {@see BuilderCatalog::FILTER_TRIGGERS}
 * filters over the core descriptors so an extension can add its own triggers and
 * actions, then hands the merged lists to a catalog. Kept separate from
 * {@see BuilderCatalog} so the catalog itself stays a pure, WordPress-free value
 * computation.
 */
final class CatalogFactory {

	/** Filter for the {@see Availability} the catalog stamps entries with. */
	public const FILTER_AVAILABILITY = 'cartquill_builder_availability';

	public static function create( ConnectionStore $connections ): BuilderCatalog {
		// Both hook names are `cartquill_`-prefixed string constants on
		// BuilderCatalog; PHPCS only reads literals, so it cannot see the prefix
		// through the class constant and reports a dynamic hook name.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		/** @var list<ActionDescriptor> $actions */
		$actions = (array) \apply_filters( BuilderCatalog::FILTER_ACTIONS, CoreActionDescriptors::all() );
		/** @var list<TriggerDescriptor> $triggers */
		$triggers = (array) \apply_filters( BuilderCatalog::FILTER_TRIGGERS, CoreTriggers::all() );
		/**
		 * The availability the catalog stamps entries with. Core offers everything
		 * it ships; an extension replaces this to describe when its own contributed
		 * descriptors are ready to use.
		 *
		 * @param Availability $availability Offers everything by default.
		 */
		$availability = \apply_filters( self::FILTER_AVAILABILITY, new OpenAvailability() );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

		if ( ! $availability instanceof Availability ) {
			$availability = new OpenAvailability();
		}

		return new BuilderCatalog( $availability, $connections, $actions, $triggers );
	}
}
