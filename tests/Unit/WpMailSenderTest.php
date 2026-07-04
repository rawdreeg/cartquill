<?php
/**
 * WpMailSender maps wp_mail()'s boolean into a SendResult and caps status at
 * "sent" (no external id, no delivery signal).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FlowForge\Model\Message;
use FlowForge\Sender\WpMailSender;
use PHPUnit\Framework\TestCase;

final class WpMailSenderTest extends TestCase {

	protected function setUp(): void {
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
	}

	private function message(): Message {
		return new Message(
			to: 'buyer@example.com',
			subject: 'Hi',
			body: '<p>Body</p>',
			from_name: 'Acme',
			from_email: 'hello@acme.test',
		);
	}

	public function test_key_is_wp_mail(): void {
		$this->assertSame( 'wp_mail', ( new WpMailSender() )->key() );
	}

	public function test_accepted_when_wp_mail_returns_true(): void {
		Functions\expect( 'wp_mail' )
			->once()
			->with(
				'buyer@example.com',
				'Hi',
				'<p>Body</p>',
				\Mockery::type( 'array' )
			)
			->andReturn( true );

		$result = ( new WpMailSender() )->send( $this->message() );

		$this->assertTrue( $result->is_accepted() );
		$this->assertNull( $result->external_id, 'wp_mail provides no external id' );
	}

	public function test_failed_when_wp_mail_returns_false(): void {
		Functions\when( 'wp_mail' )->justReturn( false );

		$result = ( new WpMailSender() )->send( $this->message() );

		$this->assertFalse( $result->is_accepted() );
		$this->assertNotNull( $result->error );
	}

	public function test_from_header_is_built_from_message(): void {
		$this->assertContains(
			'From: Acme <hello@acme.test>',
			$this->message()->header_lines()
		);
	}
}
