<?php
/**
 * In-memory MessageRepository for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Stores records in a plain array, assigning sequential ids like an
 * auto-increment column would. Mirrors the DB's unique (enrollment_id,
 * step_index) index by rejecting a duplicate insert, so idempotency can be
 * asserted even when a test bypasses the engine's own guard.
 */
final class InMemoryMessageRepository implements MessageRepository {

	/** @var array<int, MessageRecord> */
	private array $records = array();

	/** @var array<string, true> Seen (enrollment_id:step_index) keys. */
	private array $unique = array();

	private int $next_id = 1;

	public function save( MessageRecord $record ): MessageRecord {
		$key = $this->unique_key( $record->enrollment_id, $record->step_index );
		if ( null !== $key ) {
			if ( isset( $this->unique[ $key ] ) && null === $record->id ) {
				throw new \RuntimeException( "Duplicate message for {$key} (unique constraint)." );
			}
			$this->unique[ $key ] = true;
		}

		if ( null !== $record->id ) {
			$this->records[ $record->id ] = $record;
			return $record;
		}

		$id                   = $this->next_id++;
		$stored               = $record->with_id( $id );
		$this->records[ $id ] = $stored;
		return $stored;
	}

	public function claim( MessageRecord $record ): ?MessageRecord {
		$key = $this->unique_key( $record->enrollment_id, $record->step_index );
		if ( null !== $key && isset( $this->unique[ $key ] ) ) {
			return null; // Slot already taken.
		}
		return $this->save( $record );
	}

	public function find( int $id ): ?MessageRecord {
		return $this->records[ $id ] ?? null;
	}

	public function find_by_external_id( string $external_id ): ?MessageRecord {
		if ( '' === $external_id ) {
			return null;
		}
		// Newest match wins, matching the wpdb impl's ORDER BY id DESC.
		$found = null;
		foreach ( $this->records as $record ) {
			if ( $external_id === $record->external_id
				&& ( null === $found || (int) $record->id > (int) $found->id )
			) {
				$found = $record;
			}
		}
		return $found;
	}

	public function update_status( int $id, string $status ): void {
		if ( isset( $this->records[ $id ] ) ) {
			$this->records[ $id ] = $this->records[ $id ]->with_result(
				$status,
				$this->records[ $id ]->external_id,
				$this->records[ $id ]->sent_at,
			);
		}
	}

	public function exists_for_step( int $enrollment_id, int $step_index ): bool {
		return isset( $this->unique[ $enrollment_id . ':' . $step_index ] );
	}

	public function find_for_step( int $enrollment_id, int $step_index ): ?MessageRecord {
		foreach ( $this->records as $record ) {
			if ( $record->enrollment_id === $enrollment_id && $record->step_index === $step_index ) {
				return $record;
			}
		}
		return null;
	}

	/** Statuses that are never eligible for last-touch attribution. */
	private const ATTRIBUTION_EXCLUDED = array(
		MessageRecord::STATUS_QUEUED,
		MessageRecord::STATUS_FAILED,
		MessageRecord::STATUS_BOUNCED,
		MessageRecord::STATUS_COMPLAINED,
	);

	public function latest_to_recipient_between( string $email, string $from, string $to ): ?MessageRecord {
		$email = strtolower( trim( $email ) );
		$best  = null;
		foreach ( $this->records as $record ) {
			if ( strtolower( $record->recipient ) !== $email
				|| in_array( $record->status, self::ATTRIBUTION_EXCLUDED, true )
				|| null === $record->sent_at
				|| $record->sent_at < $from
				|| $record->sent_at > $to
			) {
				continue;
			}
			// On equal sent_at, the higher id wins (matches the wpdb ORDER BY).
			if ( null === $best
				|| $record->sent_at > $best->sent_at
				|| ( $record->sent_at === $best->sent_at && (int) $record->id > (int) $best->id )
			) {
				$best = $record;
			}
		}
		return $best;
	}

	public function stats_by_flow(): array {
		$stats = array();
		foreach ( $this->records as $record ) {
			if ( in_array( $record->status, array( MessageRecord::STATUS_QUEUED, MessageRecord::STATUS_FAILED ), true ) ) {
				continue;
			}
			$flow = $record->flow_id;
			if ( ! isset( $stats[ $flow ] ) ) {
				$stats[ $flow ] = array( 'sent' => 0, 'opened' => 0, 'clicked' => 0 );
			}
			++$stats[ $flow ]['sent'];
			if ( in_array( $record->status, array( MessageRecord::STATUS_OPENED, MessageRecord::STATUS_CLICKED ), true ) ) {
				++$stats[ $flow ]['opened'];
			}
			if ( MessageRecord::STATUS_CLICKED === $record->status ) {
				++$stats[ $flow ]['clicked'];
			}
		}
		return $stats;
	}

	public function delivery_stats_by_flow(): array {
		// A delivered message that was later opened/clicked has moved past the
		// `delivered` status, but it was still delivered — so opens/clicks count
		// toward delivered, mirroring how stats_by_flow folds clicks into opens.
		$delivered = array( MessageRecord::STATUS_DELIVERED, MessageRecord::STATUS_OPENED, MessageRecord::STATUS_CLICKED );

		$stats = array();
		foreach ( $this->records as $record ) {
			$bucket = null;
			if ( in_array( $record->status, $delivered, true ) ) {
				$bucket = 'delivered';
			} elseif ( MessageRecord::STATUS_BOUNCED === $record->status ) {
				$bucket = 'bounced';
			} elseif ( MessageRecord::STATUS_COMPLAINED === $record->status ) {
				$bucket = 'complained';
			}
			if ( null === $bucket ) {
				continue;
			}
			$flow = $record->flow_id;
			if ( ! isset( $stats[ $flow ] ) ) {
				$stats[ $flow ] = array( 'delivered' => 0, 'bounced' => 0, 'complained' => 0 );
			}
			++$stats[ $flow ][ $bucket ];
		}
		return $stats;
	}

	public function for_recipient( string $email ): array {
		$email = strtolower( trim( $email ) );
		return array_values(
			array_filter( $this->records, static fn( $r ) => strtolower( $r->recipient ) === $email )
		);
	}

	public function delete_for_recipient( string $email ): int {
		$email = strtolower( trim( $email ) );
		$count = 0;
		foreach ( $this->records as $id => $record ) {
			if ( strtolower( $record->recipient ) === $email ) {
				unset( $this->records[ $id ] );
				++$count;
			}
		}
		return $count;
	}

	public function all(): array {
		return array_values( $this->records );
	}

	private function unique_key( ?int $enrollment_id, int $step_index ): ?string {
		return null !== $enrollment_id ? $enrollment_id . ':' . $step_index : null;
	}
}
