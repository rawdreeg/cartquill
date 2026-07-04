<?php
/**
 * Scripted ResendClient for tests — no network.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Fake;

use FlowForge\Deliverability\DomainStatus;
use FlowForge\Deliverability\ResendClient;
use FlowForge\Deliverability\ResendException;
use FlowForge\Model\Message;

/**
 * Records the messages handed to it and returns canned ids / domain status,
 * standing in for the customer's Resend account. Call `fail()` to simulate an
 * API error.
 */
final class StubResendClient implements ResendClient {

	/** @var list<Message> */
	public array $sent = array();

	private int $counter = 0;

	private bool $throw = false;

	private ?DomainStatus $domain_status = null;

	public function __construct( private readonly string $id_prefix = 'resend-' ) {}

	public function fail(): void {
		$this->throw = true;
	}

	public function will_return_domain_status( DomainStatus $status ): void {
		$this->domain_status = $status;
	}

	public function send( Message $message ): string {
		if ( $this->throw ) {
			throw new ResendException( 'stub resend failure' );
		}
		$this->sent[] = $message;
		return $this->id_prefix . ( ++$this->counter );
	}

	/** @var list<string> Domains passed to create_domain(). */
	public array $created = array();

	public function create_domain( string $domain ): DomainStatus {
		if ( $this->throw ) {
			throw new ResendException( 'stub resend failure' );
		}
		$this->created[] = $domain;
		return $this->domain_status ?? new DomainStatus( $domain, false, 'pending', array() );
	}

	public function domain_status( string $domain ): DomainStatus {
		if ( $this->throw ) {
			throw new ResendException( 'stub resend failure' );
		}
		return $this->domain_status ?? new DomainStatus( $domain, false, 'pending', array() );
	}
}
