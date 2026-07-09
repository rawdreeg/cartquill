<?php
/**
 * A sending domain's authentication state and DNS records.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The wizard's view of a domain: its overall verification state plus the
 * SPF/DKIM/DMARC records to add. `from_resend()` maps Resend's domains API
 * response into this shape so the admin screen never touches the raw payload.
 */
final class DomainStatus {

	/**
	 * @param string             $domain   The sending domain.
	 * @param bool               $verified Whether the domain is fully verified.
	 * @param string             $state    Raw provider state (verified/pending/failed/…).
	 * @param list<DomainRecord> $records  DNS records to add.
	 * @param string             $id       Resend's domain UUID (its retrieval key).
	 */
	public function __construct(
		public readonly string $domain,
		public readonly bool $verified,
		public readonly string $state,
		public readonly array $records,
		public readonly string $id = '',
	) {}

	/**
	 * Map a Resend domain API payload into a DomainStatus.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function from_resend( array $payload ): self {
		$state   = strtolower( (string) ( $payload['status'] ?? 'not_started' ) );
		$records = array();

		foreach ( (array) ( $payload['records'] ?? array() ) as $record ) {
			$record    = (array) $record;
			$records[] = new DomainRecord(
				(string) ( $record['record'] ?? $record['purpose'] ?? '' ),
				(string) ( $record['type'] ?? '' ),
				(string) ( $record['name'] ?? '' ),
				(string) ( $record['value'] ?? '' ),
				(string) ( $record['status'] ?? 'pending' ),
				// Resend sends priority only for MX records (as an int); TTL is a
				// string like "Auto". Absent → blank so non-MX rows stay clean.
				isset( $record['priority'] ) ? (string) $record['priority'] : '',
				(string) ( $record['ttl'] ?? '' ),
			);
		}

		return new self(
			(string) ( $payload['name'] ?? '' ),
			'verified' === $state,
			$state,
			$records,
			(string) ( $payload['id'] ?? '' ),
		);
	}
}
