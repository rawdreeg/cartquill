<?php
/**
 * One DNS record the store must add to authenticate its sending domain.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A single SPF/DKIM/DMARC record surfaced by the domain-auth wizard: what to add
 * to DNS (type/name/value) and whether Resend has seen it verified yet.
 */
final class DomainRecord {

	public function __construct(
		public readonly string $purpose,
		public readonly string $type,
		public readonly string $name,
		public readonly string $value,
		public readonly string $status,
	) {}

	public function is_verified(): bool {
		return 'verified' === strtolower( $this->status );
	}
}
