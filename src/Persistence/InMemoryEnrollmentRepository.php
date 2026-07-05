<?php
/**
 * In-memory EnrollmentRepository for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class InMemoryEnrollmentRepository implements EnrollmentRepository {

	/** @var array<int, EnrollmentRecord> */
	private array $records = array();

	private int $next_id = 1;

	public function save( EnrollmentRecord $record ): EnrollmentRecord {
		if ( null !== $record->id ) {
			$this->records[ $record->id ] = $record;
			return $record;
		}

		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function find( int $id ): ?EnrollmentRecord {
		return $this->records[ $id ] ?? null;
	}

	public function find_active( int $flow_id, string $customer_email ): ?EnrollmentRecord {
		foreach ( $this->records as $record ) {
			if ( $record->flow_id === $flow_id
				&& $record->customer_email === $customer_email
				&& $record->is_active()
			) {
				return $record;
			}
		}
		return null;
	}

	public function has_any( int $flow_id, string $customer_email ): bool {
		foreach ( $this->records as $record ) {
			if ( $record->flow_id === $flow_id && $record->customer_email === $customer_email ) {
				return true;
			}
		}
		return false;
	}

	public function for_customer( string $customer_email ): array {
		$email = strtolower( trim( $customer_email ) );
		return array_values(
			array_filter( $this->records, static fn( $r ) => strtolower( $r->customer_email ) === $email )
		);
	}

	public function unsubscribe_customer( string $customer_email ): int {
		$email = strtolower( trim( $customer_email ) );
		$count = 0;
		foreach ( $this->records as $id => $record ) {
			if ( strtolower( $record->customer_email ) === $email && $record->is_active() ) {
				$this->records[ $id ] = $record->with_status( EnrollmentRecord::STATUS_UNSUBSCRIBED );
				++$count;
			}
		}
		return $count;
	}

	public function delete_for_customer( string $customer_email ): int {
		$email = strtolower( trim( $customer_email ) );
		$count = 0;
		foreach ( $this->records as $id => $record ) {
			if ( strtolower( $record->customer_email ) === $email ) {
				unset( $this->records[ $id ] );
				++$count;
			}
		}
		return $count;
	}

	public function all(): array {
		return array_values( $this->records );
	}
}
