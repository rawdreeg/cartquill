<?php
/**
 * Seam for posting to Slack.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Posts a message to a Slack incoming webhook. Kept behind an interface so the
 * action and the "Test connection" probe can be tested against a stub. Must not
 * throw for ordinary failures — return a failed {@see SlackResult} instead.
 */
interface SlackClient {

	public function post( string $webhook_url, string $channel, string $text ): SlackResult;
}
