<?php
/**
 * A row in the `cart_captures` table: a customer with an active cart.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Tracks a captured email whose cart has not yet converted. The scanner enrolls
 * pending captures older than the abandonment threshold; an order marks them
 * recovered; enrolling marks them so they are not scanned again.
 */
final class CartCaptureRecord {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_RECOVERED = 'recovered';
	public const STATUS_ENROLLED  = 'enrolled';

	public function __construct(
		public readonly ?int $id,
		public readonly string $customer_email,
		public readonly string $status = self::STATUS_PENDING,
		public readonly ?string $updated_at = null,
	) {}

	public function with_id( int $id ): self {
		return new self( $id, $this->customer_email, $this->status, $this->updated_at );
	}
}
