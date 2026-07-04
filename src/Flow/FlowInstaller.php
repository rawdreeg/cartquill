<?php
/**
 * Installs a library template as a real, editable flow.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Flow;

use FlowForge\Persistence\FlowRepository;

/**
 * One-click install: copies a template into the `flows` table as a draft the
 * store can edit and activate. The engine reads these rows, so an installed
 * flow is immediately runnable once activated.
 */
final class FlowInstaller {

	public function __construct(
		private readonly FlowLibrary $library,
		private readonly FlowRepository $flows,
	) {}

	/**
	 * @return \FlowForge\Persistence\FlowRecord|null The installed (draft) flow,
	 *                                                or null for an unknown type.
	 */
	public function install( string $type ): ?object {
		$template = $this->library->get( $type );
		if ( null === $template ) {
			return null;
		}

		return $this->flows->save( $template );
	}
}
