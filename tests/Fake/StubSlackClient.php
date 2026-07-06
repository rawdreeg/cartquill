<?php
/**
 * Test double: records Slack posts and returns a canned result.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Fake;

use CartQuill\Automations\SlackClient;
use CartQuill\Automations\SlackResult;

/**
 * Lets tests assert exactly what was posted to Slack (webhook, channel, text)
 * and force success/failure, without any HTTP.
 */
final class StubSlackClient implements SlackClient {

	/** @var list<array{webhook: string, channel: string, text: string}> */
	public array $posts = array();

	private ?SlackResult $next_result = null;

	public function post( string $webhook_url, string $channel, string $text ): SlackResult {
		$this->posts[] = array(
			'webhook' => $webhook_url,
			'channel' => $channel,
			'text'    => $text,
		);

		return $this->next_result ?? SlackResult::ok( 'slack-1700000000.000100' );
	}

	/**
	 * Force the outcome of the next post() call.
	 */
	public function will_return( SlackResult $result ): void {
		$this->next_result = $result;
	}

	public function count(): int {
		return count( $this->posts );
	}

	/**
	 * @return array{webhook: string, channel: string, text: string}|null
	 */
	public function last(): ?array {
		return $this->posts[ count( $this->posts ) - 1 ] ?? null;
	}
}
