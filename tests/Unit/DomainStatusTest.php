<?php
/**
 * Mapping Resend's domain payload into the wizard's DomainStatus.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Deliverability\DomainStatus;
use PHPUnit\Framework\TestCase;

final class DomainStatusTest extends TestCase {

	public function test_maps_records_and_verified_state(): void {
		$status = DomainStatus::from_resend(
			array(
				'name'    => 'mail.acme.test',
				'status'  => 'verified',
				'records' => array(
					array( 'record' => 'SPF', 'type' => 'TXT', 'name' => 'send', 'value' => 'v=spf1 include:resend.com ~all', 'status' => 'verified' ),
					array( 'record' => 'DKIM', 'type' => 'CNAME', 'name' => 'resend._domainkey', 'value' => 'resend._domainkey.resend.com', 'status' => 'verified' ),
				),
			)
		);

		$this->assertSame( 'mail.acme.test', $status->domain );
		$this->assertTrue( $status->verified );
		$this->assertSame( 'verified', $status->state );
		$this->assertCount( 2, $status->records );
		$this->assertSame( 'SPF', $status->records[0]->purpose );
		$this->assertSame( 'TXT', $status->records[0]->type );
		$this->assertTrue( $status->records[0]->is_verified() );
	}

	public function test_pending_domain_is_not_verified(): void {
		$status = DomainStatus::from_resend(
			array(
				'name'    => 'mail.acme.test',
				'status'  => 'pending',
				'records' => array(
					array( 'record' => 'DMARC', 'type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none;', 'status' => 'pending' ),
				),
			)
		);

		$this->assertFalse( $status->verified );
		$this->assertSame( 'pending', $status->state );
		$this->assertFalse( $status->records[0]->is_verified() );
	}

	public function test_missing_fields_default_safely(): void {
		$status = DomainStatus::from_resend( array() );

		$this->assertSame( '', $status->domain );
		$this->assertFalse( $status->verified );
		$this->assertSame( 'not_started', $status->state );
		$this->assertSame( array(), $status->records );
	}
}
