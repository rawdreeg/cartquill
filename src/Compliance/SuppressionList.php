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

	/** Default channel: the email suppression namespace (backward-compatible). */
	public const CHANNEL_EMAIL = 'email';

	/**
	 * Whether $identifier is suppressed on the given channel.
	 *
	 * $channel namespaces the list so a phone number that texted STOP is
	 * suppressed for SMS without touching the email list, and vice versa. The
	 * default keeps every existing email caller unchanged.
	 */
	public function is_suppressed( string $identifier, string $channel = self::CHANNEL_EMAIL ): bool;

	/**
	 * Add an identifier to the list for a channel.
	 *
	 * @param string $reason Free-text origin (e.g. "unsubscribe", "bounce", "sms_stop").
	 */
	public function suppress( string $identifier, string $reason = '', string $channel = self::CHANNEL_EMAIL ): void;

	/**
	 * Remove an identifier from a channel's list (GDPR erase, SMS START).
	 */
	public function remove( string $identifier, string $channel = self::CHANNEL_EMAIL ): void;
}
