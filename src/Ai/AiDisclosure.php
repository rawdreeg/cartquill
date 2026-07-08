<?php
/**
 * External-service disclosure + opt-in gate for the AI proxy.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * WordPress.org guideline 8 requires disclosing every external service and
 * gating the first call behind explicit consent. The AI add-on posts store
 * context and the license key to a hosted vendor proxy, so no proxy request is
 * made until the store owner acknowledges this once. The acknowledgement is a
 * single non-autoloaded option.
 */
final class AiDisclosure {

	public const OPTION = 'cartquill_ai_disclosure_ack';

	/**
	 * HITL: replace with the real vendor legal URLs before WP.org submission —
	 * the proxy backend at api.cartquill.com is a deferred human step.
	 */
	public const TERMS_URL   = 'https://api.cartquill.com/legal/ai-terms';
	public const PRIVACY_URL = 'https://api.cartquill.com/legal/ai-privacy';

	public function is_acknowledged(): bool {
		return (bool) \get_option( self::OPTION, false );
	}

	public function acknowledge(): void {
		\update_option( self::OPTION, true, false );
	}

	/**
	 * Plain-text disclosure of what the AI proxy receives and when. Kept in sync
	 * with the readme "External services" section.
	 */
	public function summary(): string {
		return \__(
			'Generating or rewriting copy sends the flow type, store context (store name, tone, currency, and a few top product names), any copy you ask to rewrite, and your AI license key to the CartQuill AI service at api.cartquill.com. This is a paid vendor service; drafts are returned to the builder for your review and are never sent to customers automatically.',
			'cartquill'
		);
	}
}
