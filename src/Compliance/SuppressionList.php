<?php
/**
 * The global suppression list, checked before every send.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Records addresses that must never be emailed (unsubscribed, bounced,
 * complained). The engine consults this as the first thing every send does.
 *
 * The engine slice introduces the seam with an empty list; the compliance and
 * deliverability slices populate it (unsubscribe links, bounce/complaint
 * webhooks).
 */
interface SuppressionList {

	public function is_suppressed( string $email ): bool;

	/**
	 * Add an address to the list.
	 *
	 * @param string $reason Free-text origin (e.g. "unsubscribe", "bounce").
	 */
	public function suppress( string $email, string $reason = '' ): void;

	/**
	 * Remove an address from the list (GDPR erase).
	 */
	public function remove( string $email ): void;
}
