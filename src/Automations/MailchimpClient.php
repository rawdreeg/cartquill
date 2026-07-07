<?php
/**
 * Seam for syncing a contact into a Mailchimp audience.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Upserts a member into a Mailchimp audience and adds a tag, using the store's
 * own Mailchimp API key. Kept behind an interface so the action and the
 * connection probe can be tested against a stub. Must not throw for ordinary
 * failures — return a failed {@see MailchimpResult} instead.
 *
 * This is deliberately an audience-sync seam, NOT an email sender: CartQuill
 * keeps sending its own email through SenderInterface (no Mailchimp Transactional
 * / resold sending).
 */
interface MailchimpClient {

	/**
	 * @param array<string, mixed> $credentials api_key, server_prefix, audience_id.
	 */
	public function sync( array $credentials, string $email, string $tag ): MailchimpResult;
}
