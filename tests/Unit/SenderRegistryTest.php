<?php
/**
 * The sender registry: registration, active selection, and safe fallback.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Sender\FakeSender;
use FlowForge\Sender\SenderRegistry;
use FlowForge\Sender\WpMailSender;
use PHPUnit\Framework\TestCase;

final class SenderRegistryTest extends TestCase {

	public function test_active_defaults_to_wp_mail(): void {
		$registry = new SenderRegistry( 'wp_mail' );
		$registry->register( new WpMailSender() );

		$this->assertSame( 'wp_mail', $registry->active()->key() );
	}

	public function test_an_addon_sender_can_be_selected_active(): void {
		$registry = new SenderRegistry( 'wp_mail' );
		$registry->register( new WpMailSender() );
		$registry->register( new FakeSender() ); // stands in for an add-on sender ("fake")

		$registry->set_active( 'fake' );

		$this->assertSame( 'fake', $registry->active_key() );
		$this->assertSame( 'fake', $registry->active()->key() );
	}

	public function test_selecting_an_unregistered_sender_is_ignored(): void {
		$registry = new SenderRegistry( 'wp_mail' );
		$registry->register( new WpMailSender() );

		$registry->set_active( 'resend' ); // add-on not installed

		$this->assertSame( 'wp_mail', $registry->active_key(), 'no cliff to a missing sender' );
		$this->assertSame( 'wp_mail', $registry->active()->key() );
	}

	public function test_active_falls_back_when_selection_missing(): void {
		// Configured active key points at a sender that was never registered.
		$registry = new SenderRegistry( 'ghost' );
		$registry->register( new WpMailSender() );

		$this->assertSame( 'wp_mail', $registry->active()->key() );
	}
}
