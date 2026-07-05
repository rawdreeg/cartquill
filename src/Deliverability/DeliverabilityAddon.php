<?php
/**
 * The Deliverability add-on: registers the Resend sender + wizard when licensed.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Admin\DeliverabilityPage;
use FlowForge\Compliance\SuppressionList;
use FlowForge\Licensing\License;
use FlowForge\Licensing\Plans;
use FlowForge\Persistence\MessageRepository;
use FlowForge\Sender\SenderRegistry;
use FlowForge\Support\SystemClock;

/**
 * Wires itself in only when the Deliverability plan is active. Registers
 * {@see ResendSender} through the locked `register_sender()` seam (so the engine
 * is untouched), exposes the domain-auth wizard, and — once the store has saved
 * a key and verified its sending domain — makes Resend the active sender via the
 * `flowforge_active_sender` filter. Clearing the key, or an unverified domain,
 * falls straight back to wp_mail with no flow changes.
 */
final class DeliverabilityAddon {

	public function __construct(
		private readonly EspSettings $esp,
		private readonly License $license,
		private readonly MessageRepository $messages,
		private readonly SuppressionList $suppression,
	) {}

	public function register(): void {
		\add_action( 'flowforge_register_senders', array( $this, 'register_sender' ), 10, 2 );
		\add_action( 'flowforge_register_addons', array( $this, 'register_surfaces' ) );
		\add_filter( 'flowforge_active_sender', array( $this, 'pick_active_sender' ) );
	}

	/**
	 * @param SenderRegistry $senders The sender registry.
	 * @param License        $license The licensing gate.
	 */
	public function register_sender( SenderRegistry $senders, License $license ): void {
		if ( ! $license->is_active( Plans::DELIVERABILITY ) || ! $this->esp->has_key() || ! $this->esp->is_domain_verified() ) {
			return;
		}
		$senders->register( new ResendSender( new HttpResendClient( $this->esp->api_key() ) ) );
	}

	public function register_surfaces(): void {
		if ( ! $this->license->is_active( Plans::DELIVERABILITY ) ) {
			return;
		}
		( new DeliverabilityPage( $this->esp ) )->register();

		if ( $this->esp->has_undecryptable_key() ) {
			\add_action( 'admin_notices', array( $this, 'render_undecryptable_key_notice' ) );
		}

		// Ingest Resend delivery webhooks once a signing secret is configured. A
		// real clock enables Svix replay-window enforcement at runtime.
		if ( $this->esp->has_webhook_secret() ) {
			( new WebhookEndpoint(
				new ResendWebhookVerifier( $this->esp->webhook_secret(), new SystemClock() ),
				new WebhookProcessor( $this->messages, $this->suppression ),
			) )->register();
		}
	}

	public function render_undecryptable_key_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo \esc_html__(
			'FlowForge could not decrypt your saved Resend API key (the site security keys may have changed). Sending has fallen back to wp_mail — re-enter your key on the Deliverability screen to resume.',
			'flowforge'
		);
		echo '</p></div>';
	}

	/**
	 * @param string $current The sender key selected so far.
	 */
	public function pick_active_sender( string $current ): string {
		if ( $this->license->is_active( Plans::DELIVERABILITY ) && $this->esp->has_key() && $this->esp->is_domain_verified() ) {
			return 'resend';
		}
		return $current;
	}
}
