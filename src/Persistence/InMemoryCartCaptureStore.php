<?php
/**
 * In-memory CartCaptureStore for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

final class InMemoryCartCaptureStore implements CartCaptureStore {

	/** @var array<string, CartCaptureRecord> keyed by normalized email */
	private array $records = array();

	private int $next_id = 1;

	public function capture( string $email, string $updated_at ): void {
		$key                   = $this->normalize( $email );
		$id                    = $this->records[ $key ]->id ?? $this->next_id++;
		$this->records[ $key ] = new CartCaptureRecord( $id, $key, CartCaptureRecord::STATUS_PENDING, $updated_at );
	}

	public function recover( string $email ): void {
		$this->set_status( $email, CartCaptureRecord::STATUS_RECOVERED );
	}

	public function mark_enrolled( string $email ): void {
		$this->set_status( $email, CartCaptureRecord::STATUS_ENROLLED );
	}

	public function due( string $cutoff ): array {
		$due = array();
		foreach ( $this->records as $record ) {
			if ( CartCaptureRecord::STATUS_PENDING === $record->status
				&& null !== $record->updated_at
				&& $record->updated_at <= $cutoff
			) {
				$due[] = $record->customer_email;
			}
		}
		return $due;
	}

	public function find( string $email ): ?CartCaptureRecord {
		return $this->records[ $this->normalize( $email ) ] ?? null;
	}

	private function set_status( string $email, string $status ): void {
		$key = $this->normalize( $email );
		if ( isset( $this->records[ $key ] ) ) {
			$record                = $this->records[ $key ];
			$this->records[ $key ] = new CartCaptureRecord( $record->id, $key, $status, $record->updated_at );
		}
	}

	private function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}
}
