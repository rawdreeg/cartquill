<?php
/**
 * Deliverability add-on sender: delivers via the customer's Resend account.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

use FlowForge\Model\Message;
use FlowForge\Model\SendResult;
use FlowForge\Sender\SenderInterface;

/**
 * Swaps in behind SenderInterface with zero engine changes: the store selects
 * "resend" as the active sender and every step routes here. Unlike wp_mail,
 * Resend returns a real message id, which becomes the SendResult external id so
 * later webhook events (delivered/bounced/complained, added in #14) correlate
 * back to the send. Delivery failures return a failed SendResult — never throw.
 */
final class ResendSender implements SenderInterface {

	public function __construct( private readonly ResendClient $client ) {}

	public function key(): string {
		return 'resend';
	}

	public function send( Message $message ): SendResult {
		try {
			return SendResult::accepted( $this->client->send( $message ) );
		} catch ( ResendException $e ) {
			return SendResult::failed( $e->getMessage() );
		}
	}
}
