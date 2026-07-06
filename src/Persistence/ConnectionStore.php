<?php
/**
 * Persistence seam for the `connections` table.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Stores one connection per external service, keyed by service. Keeps the
 * add-on's actions free of $wpdb so they can be tested against an in-memory
 * implementation; the wpdb-backed implementation (with encrypted credentials)
 * is used at runtime.
 */
interface ConnectionStore {

	/**
	 * Upsert the connection for its service (one row per service) and return it
	 * with its id populated.
	 */
	public function save( ConnectionRecord $record ): ConnectionRecord;

	/**
	 * The connection for a service, or null if none is stored.
	 */
	public function find( string $service ): ?ConnectionRecord;

	/**
	 * Remove a service's connection.
	 */
	public function delete( string $service ): void;

	/**
	 * All stored connections.
	 *
	 * @return list<ConnectionRecord>
	 */
	public function all(): array;
}
