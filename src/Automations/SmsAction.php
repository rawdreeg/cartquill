<?php
/**
 * The SMS action: text the customer via Twilio.
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
 * A customer-facing action that texts the customer via Twilio. Because it reaches
 * the customer, it IS subject to suppression: {@see self::target()} returns the
 * normalized phone the runner checks against the SMS suppression list before
 * sending, so a number that texted STOP is never messaged again. The message body
 * comes from the step config, rendered against the enrollment's trigger context
 * (phone, tracking url, ...). A missing connection or a failed send returns a
 * failed result, which the engine retries and then dead-letters.
 */
final class SmsAction implements ActionInterface {

	public const TYPE    = 'sms_send';
	public const SERVICE = 'twilio';

	public function __construct(
		private readonly ConnectionStore $connections,
		private readonly TwilioClient $client,
		private readonly Renderer $renderer,
	) {}

	public function type(): string {
		return self::TYPE;
	}

	public function sender_key(): string {
		return self::SERVICE;
	}

	public function is_customer_facing(): bool {
		return true;
	}

	public function target( ActionContext $context ): ?string {
		$phone = Phone::normalize( (string) $context->value( 'phone', '' ) );
		return '' !== $phone ? $phone : null;
	}

	public function execute( ActionContext $context ): SendResult {
		$phone = $this->target( $context );
		if ( null === $phone ) {
			return SendResult::failed( 'No phone number on file.' );
		}

		$connection = $this->connections->find( self::SERVICE );
		if ( null === $connection ) {
			return SendResult::failed( 'Twilio connection is not configured.' );
		}

		$credentials = array(
			'account_sid' => (string) $connection->credential( 'account_sid', '' ),
			'auth_token'  => (string) $connection->credential( 'auth_token', '' ),
			'from_number' => (string) $connection->credential( 'from_number', '' ),
		);
		if ( '' === $credentials['account_sid'] || '' === $credentials['auth_token'] || '' === $credentials['from_number'] ) {
			return SendResult::failed( 'Twilio connection is incomplete.' );
		}

		$body = $this->renderer->render_text(
			(string) $context->config( 'text', 'Your order from {{ store_name }} has shipped.' ),
			array_merge( $context->context, array( 'customer_email' => $context->customer_email ) )
		);

		$result = $this->client->send( $credentials, $phone, $body );

		return $result->ok
			? SendResult::accepted( $result->sid )
			: SendResult::failed( $result->error ?? 'SMS send failed.' );
	}
}
