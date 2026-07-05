<?php
/**
 * Contract with the customer's Resend account.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

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
	 * Register a sending domain in the customer's Resend account, returning the
	 * DNS records to add and the domain's Resend UUID (its retrieval key). Used
	 * by the wizard to bootstrap a domain the store hasn't created yet.
	 *
	 * @throws ResendException On transport failure or an API error.
	 */
	public function create_domain( string $domain ): DomainStatus;

	/**
	 * Trigger a DNS re-check of a domain by its Resend UUID.
	 *
	 * @throws ResendException On transport failure or an API error.
	 */
	public function verify_domain( string $domain_id ): void;

	/**
	 * Fetch the SPF/DKIM/DMARC records and verification state for a domain by
	 * its Resend UUID (Resend keys domain retrieval by id, not name).
	 *
	 * @throws ResendException On transport failure or an API error.
	 */
	public function domain_status( string $domain_id ): DomainStatus;

	/**
	 * Resolve a domain name to its Resend UUID via the domains list, for a
	 * domain created outside the wizard. Returns null when not found.
	 *
	 * @throws ResendException On transport failure or an API error.
	 */
	public function find_domain_id( string $domain ): ?string;
}
