<?php
/**
 * The Deliverability add-on: registers the Resend sender + wizard when licensed.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

use FlowForge\Admin\DeliverabilityPage;
use FlowForge\Licensing\License;
use FlowForge\Licensing\Plans;
use FlowForge\Sender\SenderRegistry;

/**
 * Wires itself in only when the Deliverability plan is active. Registers
 * {@see ResendSender} through the locked `register_sender()` seam (so the engine
 * is untouched), exposes the domain-auth wizard, and — once the store has saved
 * a key — makes Resend the active sender via the `flowforge_active_sender`
 * filter. Clearing the key falls straight back to wp_mail with no flow changes.
 */
final class DeliverabilityAddon {

	public function __construct(
		private readonly EspSettings $esp,
		private readonly License $license,
	) {}

	public function register(): void {
		\add_action( 'flowforge_register_senders', array( $this, 'register_sender' ), 10, 2 );
		\add_action( 'flowforge_register_addons', array( $this, 'register_admin' ) );
		\add_filter( 'flowforge_active_sender', array( $this, 'pick_active_sender' ) );
	}

	/**
	 * @param SenderRegistry $senders The sender registry.
	 * @param License        $license The licensing gate.
	 */
	public function register_sender( SenderRegistry $senders, License $license ): void {
		if ( ! $license->is_active( Plans::DELIVERABILITY ) || ! $this->esp->has_key() ) {
			return;
		}
		$senders->register( new ResendSender( new HttpResendClient( $this->esp->api_key() ) ) );
	}

	public function register_admin(): void {
		if ( ! $this->license->is_active( Plans::DELIVERABILITY ) ) {
			return;
		}
		( new DeliverabilityPage( $this->esp ) )->register();
	}

	/**
	 * @param string $current The sender key selected so far.
	 */
	public function pick_active_sender( string $current ): string {
		if ( $this->license->is_active( Plans::DELIVERABILITY ) && $this->esp->has_key() ) {
			return 'resend';
		}
		return $current;
	}
}
