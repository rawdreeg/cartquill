<?php
/**
 * $wpdb-backed ConnectionStore (runtime implementation).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/*
 * Direct $wpdb access is this class's entire job: CartQuill owns its custom
 * tables (see Persistence\Schema) and WordPress ships no API for them. Table
 * names come from Schema::*_table() - `$wpdb->prefix` plus a hard-coded literal,
 * never user input - and an identifier cannot travel through a prepare()
 * placeholder, so the name is interpolated while every *value* stays prepared.
 * Rows are read uncached deliberately: they hold credentials encrypted
 * at rest, which belong in one place rather than copied into a persistent object
 * cache that may outlive a disconnect.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above.

/**
 * Persists connections to the custom `connections` table via $wpdb, one row per
 * service. Credentials are encrypted at rest with {@see EncryptedCredentials}
 * (never stored in the clear). A thin translation layer, kept out of the tested
 * core; the encryption itself is unit-tested at the EncryptedCredentials seam.
 */
final class WpdbConnectionStore implements ConnectionStore {

	public function __construct( private readonly EncryptedCredentials $cipher ) {}

	public function save( ConnectionRecord $record ): ConnectionRecord {
		global $wpdb;
		$table = Schema::connections_table();

		$data = array(
			'service'     => $record->service,
			'status'      => $record->status,
			'credentials' => $this->cipher->encode( $record->credentials ),
			'updated_at'  => \current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s', '%s', '%s' );

		$existing = $this->find_id( $record->service );
		if ( null !== $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ), $formats, array( '%d' ) );
			return $record->with_id( $existing );
		}

		$wpdb->insert( $table, $data, $formats );
		return $record->with_id( (int) $wpdb->insert_id );
	}

	public function find( string $service ): ?ConnectionRecord {
		global $wpdb;
		$table = Schema::connections_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE service = %s LIMIT 1", $service ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function delete( string $service ): void {
		global $wpdb;
		$wpdb->delete( Schema::connections_table(), array( 'service' => $service ), array( '%s' ) );
	}

	public function all(): array {
		global $wpdb;
		$table = Schema::connections_table();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY service ASC", ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	private function find_id( string $service ): ?int {
		global $wpdb;
		$table = Schema::connections_table();

		$id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE service = %s LIMIT 1", $service )
		);

		return null !== $id ? (int) $id : null;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): ConnectionRecord {
		return new ConnectionRecord(
			id: (int) $row['id'],
			service: (string) $row['service'],
			status: (string) $row['status'],
			credentials: $this->cipher->decode( isset( $row['credentials'] ) ? (string) $row['credentials'] : null ),
			updated_at: isset( $row['updated_at'] ) && null !== $row['updated_at'] ? (string) $row['updated_at'] : null,
		);
	}
}
