<?php
/**
 * The id-keyed Resend domain contract, exercised through the stub client.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Deliverability\DomainStatus;
use FlowForge\Tests\Fake\StubResendClient;
use PHPUnit\Framework\TestCase;

final class ResendDomainContractTest extends TestCase {

	public function test_create_captures_the_id_then_verify_and_status_key_on_it(): void {
		$client = new StubResendClient();
		$client->will_return_domain_status(
			new DomainStatus( 'mail.acme.test', true, 'verified', array(), 'dom_123' )
		);

		$created = $client->create_domain( 'mail.acme.test' );
		$this->assertSame( 'dom_123', $created->id, 'the domain UUID is captured on creation' );

		$this->assertSame( 'dom_123', $client->find_domain_id( 'mail.acme.test' ), 'a name resolves to its UUID' );

		$client->verify_domain( $created->id );
		$this->assertSame( array( 'dom_123' ), $client->verified_ids, 'verification is keyed on the UUID, not the name' );

		$status = $client->domain_status( $created->id );
		$this->assertTrue( $status->verified );
	}
}
