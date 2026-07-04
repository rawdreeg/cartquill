<?php
/**
 * Contract with the customer's Resend account.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

use FlowForge\Model\Message;

/**
 * The seam between the add-on and Resend's HTTP API, authenticated with the
 * *customer's own* API key (the vendor never resells sending). Kept minimal:
 * send an email and read a domain's authentication status. Stubbed in tests.
 */
interface ResendClient {

	/**
	 * Send a message. Returns the Resend message id.
	 *
	 * @throws ResendException On transport failure or an API error.
	 */
	public function send( Message $message ): string;

	/**
	 * Fetch the SPF/DKIM/DMARC records and verification state for a domain,
	 * powering the domain-auth wizard.
	 *
	 * @throws ResendException On transport failure or an API error.
	 */
	public function domain_status( string $domain ): DomainStatus;
}
