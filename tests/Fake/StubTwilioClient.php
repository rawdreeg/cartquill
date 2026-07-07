<?php
/**
 * Test double: records SMS sends and returns a canned result.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Fake;

use CartQuill\Automations\SmsResult;
use CartQuill\Automations\TwilioClient;

/**
 * Lets tests assert exactly who was texted and with what body, and force
 * success/failure, without any HTTP.
 */
final class StubTwilioClient implements TwilioClient {

	/** @var list<array{to: string, body: string, credentials: array<string, mixed>}> */
	public array $sends = array();

	private ?SmsResult $next_result = null;

	public function send( array $credentials, string $to, string $body ): SmsResult {
		$this->sends[] = array(
			'to'          => $to,
			'body'        => $body,
			'credentials' => $credentials,
		);

		return $this->next_result ?? SmsResult::ok( 'SM1234567890' );
	}

	public function will_return( SmsResult $result ): void {
		$this->next_result = $result;
	}

	public function count(): int {
		return count( $this->sends );
	}

	/**
	 * @return array{to: string, body: string, credentials: array<string, mixed>}|null
	 */
	public function last(): ?array {
		return $this->sends[ count( $this->sends ) - 1 ] ?? null;
	}
}
