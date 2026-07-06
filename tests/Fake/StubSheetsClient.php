<?php
/**
 * Test double: records Sheets appends and returns a canned result.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Fake;

use CartQuill\Automations\SheetsClient;
use CartQuill\Automations\SheetsResult;

/**
 * Lets tests assert exactly what was appended (spreadsheet id, range, row) and
 * force success/failure, without any HTTP or real Google auth.
 */
final class StubSheetsClient implements SheetsClient {

	/** @var list<array{spreadsheet_id: string, range: string, row: list<string>, service_account: array<string, mixed>}> */
	public array $appends = array();

	private ?SheetsResult $next_result = null;

	public function append( array $service_account, string $spreadsheet_id, string $range, array $row ): SheetsResult {
		$this->appends[] = array(
			'spreadsheet_id'  => $spreadsheet_id,
			'range'           => $range,
			'row'             => array_values( $row ),
			'service_account' => $service_account,
		);

		return $this->next_result ?? SheetsResult::ok( 'Sheet1!A5:C5' );
	}

	/**
	 * Force the outcome of the next append() call.
	 */
	public function will_return( SheetsResult $result ): void {
		$this->next_result = $result;
	}

	public function count(): int {
		return count( $this->appends );
	}

	/**
	 * @return array{spreadsheet_id: string, range: string, row: list<string>, service_account: array<string, mixed>}|null
	 */
	public function last(): ?array {
		return $this->appends[ count( $this->appends ) - 1 ] ?? null;
	}
}
