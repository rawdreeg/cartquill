<?php
/**
 * One DNS record the store must add to authenticate its sending domain.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A single SPF/DKIM/DMARC record surfaced by the domain-auth wizard: what to add
 * to DNS (type/name/value) and whether Resend has seen it verified yet. MX
 * records also carry a priority and TTL — an MX added without its priority (Resend's
 * Return-Path MX needs priority 10) silently fails SPF alignment, so both are
 * captured and shown. Non-MX records leave them blank.
 */
final class DomainRecord {

	public function __construct(
		public readonly string $purpose,
		public readonly string $type,
		public readonly string $name,
		public readonly string $value,
		public readonly string $status,
		public readonly string $priority = '',
		public readonly string $ttl = '',
	) {}

	public function is_verified(): bool {
		return 'verified' === strtolower( $this->status );
	}
}
