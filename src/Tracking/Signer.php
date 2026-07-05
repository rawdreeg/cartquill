<?php
/**
 * HMAC signer for tamper-proof tracking links.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Signs and verifies tracking payloads so the open pixel and click redirect
 * cannot be forged. In particular the click redirect only ever sends the user
 * to a URL we signed, closing the open-redirect hole.
 */
final class Signer {

	public function __construct( private readonly string $secret ) {}

	public function sign( string $payload ): string {
		return substr( hash_hmac( 'sha256', $payload, $this->secret ), 0, 32 );
	}

	public function verify( string $payload, string $signature ): bool {
		return hash_equals( $this->sign( $payload ), $signature );
	}
}
