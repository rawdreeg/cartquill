<?php
/**
 * Seam for sending an SMS via Twilio.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Sends a text message through the store's own Twilio account. Kept behind an
 * interface so the action can be tested against a stub. Must not throw for
 * ordinary failures — return a failed {@see SmsResult} instead.
 */
interface TwilioClient {

	/**
	 * @param array<string, mixed> $credentials account_sid, auth_token, from_number.
	 */
	public function send( array $credentials, string $to, string $body ): SmsResult;
}
