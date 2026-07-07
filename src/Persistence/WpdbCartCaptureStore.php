<?php
/**
 * $wpdb-backed CartCaptureStore (runtime implementation).
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class WpdbCartCaptureStore implements CartCaptureStore {

	public function capture( string $email, string $updated_at, float $cart_value = 0.0 ): void {
		global $wpdb;
		$table = Schema::cart_captures_table();

		// Upsert on the unique customer_email; capturing re-opens the row as pending
		// and refreshes the cart total.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (customer_email, status, updated_at, cart_value) VALUES (%s, %s, %s, %f)
				ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at), cart_value = VALUES(cart_value)", // phpcs:ignore WordPress.DB.PreparedSQL
				$this->normalize( $email ),
				CartCaptureRecord::STATUS_PENDING,
				$updated_at,
				$cart_value
			)
		);
	}

	public function recover( string $email ): void {
		$this->set_status( $email, CartCaptureRecord::STATUS_RECOVERED );
	}

	public function mark_enrolled( string $email ): void {
		$this->set_status( $email, CartCaptureRecord::STATUS_ENROLLED );
	}

	public function due( string $cutoff ): array {
		global $wpdb;
		$table = Schema::cart_captures_table();

		$emails = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT customer_email FROM {$table} WHERE status = %s AND updated_at <= %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				CartCaptureRecord::STATUS_PENDING,
				$cutoff
			)
		);

		return array_map( 'strval', $emails ?: array() );
	}

	public function find( string $email ): ?CartCaptureRecord {
		global $wpdb;
		$table = Schema::cart_captures_table();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE customer_email = %s", $this->normalize( $email ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return new CartCaptureRecord(
			id: (int) $row['id'],
			customer_email: (string) $row['customer_email'],
			status: (string) $row['status'],
			updated_at: null !== $row['updated_at'] ? (string) $row['updated_at'] : null,
			cart_value: isset( $row['cart_value'] ) ? (float) $row['cart_value'] : 0.0,
		);
	}

	public function delete( string $email ): void {
		global $wpdb;
		$wpdb->delete(
			Schema::cart_captures_table(),
			array( 'customer_email' => $this->normalize( $email ) ),
			array( '%s' )
		);
	}

	private function set_status( string $email, string $status ): void {
		global $wpdb;
		$table = Schema::cart_captures_table();

		$wpdb->update(
			$table,
			array( 'status' => $status ),
			array( 'customer_email' => $this->normalize( $email ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	private function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}
}
