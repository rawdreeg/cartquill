<?php
/**
 * Persistence seam for the `flows` table.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

interface FlowRepository {

	public function save( FlowRecord $record ): FlowRecord;

	public function find( int $id ): ?FlowRecord;

	/**
	 * Active flows of a given type — the set a trigger enrolls customers into.
	 *
	 * @return list<FlowRecord>
	 */
	public function active_by_type( string $type ): array;

	/**
	 * @return list<FlowRecord>
	 */
	public function all(): array;
}
