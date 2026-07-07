<?php
/**
 * The Mailchimp action: sync a contact into an audience and tag them.
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
use CartQuill\Model\SendResult;
use CartQuill\Persistence\ConnectionStore;

/**
 * An internal (non-customer-facing) audience-sync action: it upserts the
 * customer into the store's Mailchimp audience and adds the step's tag (which
 * drives the customer's Mailchimp journey). It is NOT an email sender — CartQuill
 * keeps sending its own recovery/welcome email through SenderInterface — so it
 * reaches no customer inbox and suppression is never consulted. Consent is
 * enforced upstream by the recipe's marketing_opt_in gate. A missing connection
 * or a failed sync returns a failed result, which the engine retries and then
 * dead-letters.
 */
final class MailchimpAction implements ActionInterface {

	public const TYPE    = 'mailchimp_sync';
	public const SERVICE = 'mailchimp';
	public const SENDER  = 'mailchimp';

	public function __construct(
		private readonly ConnectionStore $connections,
		private readonly MailchimpClient $client,
	) {}

	public function type(): string {
		return self::TYPE;
	}

	public function sender_key(): string {
		return self::SENDER;
	}

	public function is_customer_facing(): bool {
		return false;
	}

	public function target( ActionContext $context ): ?string {
		return null; // Internal audience sync: reaches no customer inbox.
	}

	public function execute( ActionContext $context ): SendResult {
		$connection = $this->connections->find( self::SERVICE );
		if ( null === $connection ) {
			return SendResult::failed( 'Mailchimp connection is not configured.' );
		}

		$credentials = array(
			'api_key'       => (string) $connection->credential( 'api_key', '' ),
			'server_prefix' => (string) $connection->credential( 'server_prefix', '' ),
			'audience_id'   => (string) $connection->credential( 'audience_id', '' ),
		);
		if ( '' === $credentials['api_key'] || '' === $credentials['server_prefix'] || '' === $credentials['audience_id'] ) {
			return SendResult::failed( 'Mailchimp connection is incomplete.' );
		}

		$tag    = (string) $context->config( 'tag', '' );
		$result = $this->client->sync( $credentials, $context->customer_email, $tag );

		return $result->ok
			? SendResult::accepted( $result->ref )
			: SendResult::failed( $result->error ?? 'Mailchimp sync failed.' );
	}
}
