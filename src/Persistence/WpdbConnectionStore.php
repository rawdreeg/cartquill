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
			$wpdb->prepare( "SELECT * FROM {$table} WHERE service = %s LIMIT 1", $service ), // phpcs:ignore WordPress.DB.PreparedSQL
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

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY service ASC", ARRAY_A ); // phpcs:ignore WordPress.DB

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	private function find_id( string $service ): ?int {
		global $wpdb;
		$table = Schema::connections_table();

		$id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE service = %s LIMIT 1", $service ) // phpcs:ignore WordPress.DB.PreparedSQL
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
