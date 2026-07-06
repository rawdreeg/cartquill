<?php
/**
 * The Google Sheets action: append a row logging a store event.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Action\ActionContext;
use CartQuill\Action\ActionInterface;
use CartQuill\Flow\Renderer;
use CartQuill\Model\SendResult;
use CartQuill\Persistence\ConnectionStore;

/**
 * An internal (non-customer-facing) action that appends a row to the store's own
 * Google Sheet — it reaches no customer inbox, so suppression is never
 * consulted. The service-account credentials, spreadsheet id and target range
 * come from the encrypted `sheets` connection; the row's columns come from the
 * step config and are rendered against the enrollment's trigger context. A
 * missing connection or a failed append returns a failed result, which the
 * engine retries and then dead-letters.
 */
final class SheetsAction implements ActionInterface {

	public const TYPE    = 'sheets_append';
	public const SERVICE = 'sheets';

	/** Default columns logged when a step provides none. */
	private const DEFAULT_COLUMNS = array( '{{ order_id }}', '{{ customer_email }}', '{{ order_total }}' );

	public function __construct(
		private readonly ConnectionStore $connections,
		private readonly SheetsClient $client,
		private readonly Renderer $renderer,
	) {}

	public function type(): string {
		return self::TYPE;
	}

	public function sender_key(): string {
		return 'google_sheets';
	}

	public function is_customer_facing(): bool {
		return false;
	}

	public function target( ActionContext $context ): ?string {
		return null; // Internal: reaches no customer destination.
	}

	public function execute( ActionContext $context ): SendResult {
		$connection = $this->connections->find( self::SERVICE );
		if ( null === $connection ) {
			return SendResult::failed( 'Google Sheets connection is not configured.' );
		}

		$service_account = json_decode( (string) $connection->credential( 'service_account', '' ), true );
		$spreadsheet_id  = (string) $connection->credential( 'spreadsheet_id', '' );
		$range           = (string) $connection->credential( 'range', 'Sheet1' );
		if ( ! is_array( $service_account ) || '' === $spreadsheet_id ) {
			return SendResult::failed( 'Google Sheets connection is incomplete.' );
		}

		$result = $this->client->append( $service_account, $spreadsheet_id, $range, $this->row( $context ) );

		return $result->ok
			? SendResult::accepted( $result->ref )
			: SendResult::failed( $result->error ?? 'Sheets append failed.' );
	}

	/**
	 * The rendered cell values for this row.
	 *
	 * @return list<string>
	 */
	private function row( ActionContext $context ): array {
		$columns = $context->config( 'columns', self::DEFAULT_COLUMNS );
		$columns = is_array( $columns ) && array() !== $columns ? $columns : self::DEFAULT_COLUMNS;

		$data = array_merge(
			$context->context,
			array( 'customer_email' => $context->customer_email )
		);

		return array_values(
			array_map(
				fn ( $template ): string => $this->renderer->render_text( (string) $template, $data ),
				$columns
			)
		);
	}
}
