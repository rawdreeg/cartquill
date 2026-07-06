<?php
/**
 * In-memory ConnectionStore for tests.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Keeps connections in a plain array keyed by service, mirroring the DB's unique
 * service index (a second save for the same service updates it). Credentials are
 * held decrypted — the at-rest encryption is a wpdb-implementation concern.
 */
final class InMemoryConnectionStore implements ConnectionStore {

	/** @var array<string, ConnectionRecord> keyed by service */
	private array $records = array();

	private int $next_id = 1;

	public function save( ConnectionRecord $record ): ConnectionRecord {
		$existing = $this->records[ $record->service ] ?? null;
		$id       = $record->id ?? $existing?->id ?? $this->next_id++;

		$stored                          = $record->with_id( $id );
		$this->records[ $record->service ] = $stored;
		return $stored;
	}

	public function find( string $service ): ?ConnectionRecord {
		return $this->records[ $service ] ?? null;
	}

	public function delete( string $service ): void {
		unset( $this->records[ $service ] );
	}

	public function all(): array {
		return array_values( $this->records );
	}
}
