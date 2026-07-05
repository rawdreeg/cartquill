<?php
/**
 * Verifies inbound ESP webhook signatures.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The seam the webhook endpoint consults before trusting a payload (locked rule:
 * ESP webhook signatures are verified). Given the raw request body and its
 * headers, `verify()` returns whether the signature is authentic — the endpoint
 * rejects anything it returns false for.
 */
interface WebhookVerifier {

	/**
	 * @param array<string, string> $headers Request headers, lower-cased keys.
	 */
	public function verify( string $payload, array $headers ): bool;
}
