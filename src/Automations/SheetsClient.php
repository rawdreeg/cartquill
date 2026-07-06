<?php
/**
 * Seam for appending rows to a Google Sheet.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Appends a row to a spreadsheet via the Sheets `values.append` API,
 * authenticating with the store's own service-account credentials. Kept behind
 * an interface so the action and the connection probe can be tested against a
 * stub. Must not throw for ordinary failures — return a failed
 * {@see SheetsResult} instead.
 */
interface SheetsClient {

	/**
	 * @param array<string, mixed> $service_account Decoded service-account JSON (client_email, private_key, …).
	 * @param list<string>         $row             The cell values to append.
	 */
	public function append( array $service_account, string $spreadsheet_id, string $range, array $row ): SheetsResult;
}
