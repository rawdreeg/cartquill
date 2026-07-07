<?php
/**
 * Test double: records Mailchimp syncs and returns a canned result.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Fake;

use CartQuill\Automations\MailchimpClient;
use CartQuill\Automations\MailchimpResult;

/**
 * Lets tests assert exactly who was synced and with what tag, and force
 * success/failure, without any HTTP.
 */
final class StubMailchimpClient implements MailchimpClient {

	/** @var list<array{email: string, tag: string, credentials: array<string, mixed>}> */
	public array $syncs = array();

	private ?MailchimpResult $next_result = null;

	public function sync( array $credentials, string $email, string $tag ): MailchimpResult {
		$this->syncs[] = array(
			'email'       => $email,
			'tag'         => $tag,
			'credentials' => $credentials,
		);

		return $this->next_result ?? MailchimpResult::ok( 'member-abc123' );
	}

	/**
	 * Force the outcome of the next sync() call.
	 */
	public function will_return( MailchimpResult $result ): void {
		$this->next_result = $result;
	}

	public function count(): int {
		return count( $this->syncs );
	}

	/**
	 * @return array{email: string, tag: string, credentials: array<string, mixed>}|null
	 */
	public function last(): ?array {
		return $this->syncs[ count( $this->syncs ) - 1 ] ?? null;
	}
}
