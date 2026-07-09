<?php
/**
 * The Deliverability add-on: registers the Resend sender + wizard when licensed.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Admin\DeliverabilityPage;
use CartQuill\Compliance\SuppressionList;
use CartQuill\Licensing\License;
use CartQuill\Licensing\Plans;
use CartQuill\Persistence\MessageRepository;
use CartQuill\Sender\SenderRegistry;
use CartQuill\Settings\Settings;
use CartQuill\Support\SystemClock;

/**
 * Wires itself in only when the Deliverability plan is active. Registers
 * {@see ResendSender} through the locked `register_sender()` seam (so the engine
 * is untouched), exposes the domain-auth wizard, and — once the store has saved a
 * key, verified its sending domain, AND set a From-address that domain covers
 * ({@see FromDomainGuard}) — makes Resend the active sender via the
 * `cartquill_active_sender` filter. Clearing the key, an unverified domain, or a
 * From-address on an unverified domain falls straight back to wp_mail with no
 * flow changes.
 */
final class DeliverabilityAddon {

	public function __construct(
		private readonly EspSettings $esp,
		private readonly License $license,
		private readonly MessageRepository $messages,
		private readonly SuppressionList $suppression,
		private readonly Settings $settings,
	) {}

	public function register(): void {
		\add_action( 'cartquill_register_senders', array( $this, 'register_sender' ), 10, 2 );
		\add_action( 'cartquill_register_addons', array( $this, 'register_surfaces' ) );
		\add_filter( 'cartquill_active_sender', array( $this, 'pick_active_sender' ) );
		\add_filter( 'cartquill_delivery_tracking_ready', array( $this, 'delivery_tracking_ready' ) );
	}

	/**
	 * Whether the store is actually set up to receive delivery events: licensed,
	 * key present, domain verified, AND a webhook secret configured (without it
	 * the endpoint never registers). Lets the reporting screen show an honest
	 * "tracking active" banner instead of claiming it on the license alone.
	 *
	 * @param bool $ready Readiness reported by any earlier provider.
	 */
	public function delivery_tracking_ready( bool $ready ): bool {
		return $ready || ( $this->credentials_ready( $this->license ) && $this->esp->has_webhook_secret() );
	}

	/**
	 * @param SenderRegistry $senders The sender registry.
	 * @param License        $license The licensing gate.
	 */
	public function register_sender( SenderRegistry $senders, License $license ): void {
		if ( ! $this->resend_ready( $license ) ) {
			return;
		}
		$senders->register( new ResendSender( new HttpResendClient( $this->esp->api_key() ) ) );
	}

	public function register_surfaces(): void {
		// Keep the domain-health re-poll self-cleaning regardless of license state,
		// so a lapsed license (add-on still present) doesn't orphan a recurring
		// action. The listener is always bound; the schedule tracks configuration.
		\add_action( DomainHealthMonitor::HOOK, array( $this, 'run_health_check' ) );
		$this->sync_health_check_schedule();

		if ( ! $this->license->is_active( Plans::DELIVERABILITY ) ) {
			return;
		}
		( new DeliverabilityPage( $this->esp ) )->register();

		if ( $this->esp->has_undecryptable_key() ) {
			\add_action( 'admin_notices', array( $this, 'render_undecryptable_key_notice' ) );
		}

		// A broken signing secret is silent — the webhook endpoint below never
		// registers — so warn explicitly rather than letting delivery events and
		// bounce auto-suppression quietly stop.
		if ( $this->esp->has_undecryptable_secret() ) {
			\add_action( 'admin_notices', array( $this, 'render_undecryptable_secret_notice' ) );
		}

		// Everything is provisioned except the From-address: it sits on a domain
		// Resend hasn't verified, so Resend is being held back to wp_mail and every
		// send would 554-bounce if forced. Tell the operator exactly why.
		if ( $this->from_domain_mismatch() ) {
			\add_action( 'admin_notices', array( $this, 'render_from_domain_mismatch_notice' ) );
		}

		// Ingest Resend delivery webhooks once a signing secret is configured. A
		// real clock enables Svix replay-window enforcement at runtime.
		if ( $this->esp->has_webhook_secret() ) {
			( new WebhookEndpoint(
				new ResendWebhookVerifier( $this->esp->webhook_secret(), new SystemClock() ),
				new WebhookProcessor( $this->messages, $this->suppression ),
				$this->esp,
			) )->register();
		}
	}

	public function render_undecryptable_key_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo \esc_html__(
			'CartQuill could not decrypt your saved Resend API key (the site security keys may have changed). Sending has fallen back to wp_mail — re-enter your key on the Deliverability screen to resume.',
			'cartquill'
		);
		echo '</p></div>';
	}

	/**
	 * Recurring Action Scheduler callback: re-poll Resend for the sending domain's
	 * current verification state and write it back.
	 */
	public function run_health_check(): void {
		( new DomainHealthMonitor( $this->esp, new HttpResendClient( $this->esp->api_key() ) ) )->refresh();
	}

	/**
	 * Keep the recurring domain-health re-poll scheduled exactly while there is a
	 * provisioned domain to check, so it self-cleans when the store deconfigures.
	 */
	private function sync_health_check_schedule(): void {
		$configured = $this->license->is_active( Plans::DELIVERABILITY )
			&& '' !== $this->esp->domain_id()
			&& $this->esp->has_key();

		if ( $configured ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& function_exists( 'as_schedule_recurring_action' )
				&& ! \as_has_scheduled_action( DomainHealthMonitor::HOOK )
			) {
				\as_schedule_recurring_action( time(), DomainHealthMonitor::INTERVAL, DomainHealthMonitor::HOOK, array(), 'cartquill' );
			}
		} elseif ( function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( DomainHealthMonitor::HOOK, array(), 'cartquill' );
		}
	}

	public function render_undecryptable_secret_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo \esc_html__(
			'CartQuill could not decrypt your saved Resend webhook signing secret (the site security keys may have changed). Delivery, bounce, and complaint events are no longer being received — re-enter your webhook signing secret on the Deliverability screen to resume.',
			'cartquill'
		);
		echo '</p></div>';
	}

	public function render_from_domain_mismatch_notice(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: 1: configured From address, 2: verified sending domain. */
			\esc_html__(
				'CartQuill verified the sending domain %2$s with Resend, but your From address (%1$s) is on a different domain. Resend rejects mail it cannot authenticate, so sending has stayed on wp_mail. Set the From email under CartQuill → Settings to an address on %2$s to switch to Resend.',
				'cartquill'
			),
			'<code>' . \esc_html( $this->settings->from_email() ) . '</code>',
			'<code>' . \esc_html( $this->esp->domain() ) . '</code>'
		);
		echo '</p></div>';
	}

	/**
	 * @param string $current The sender key selected so far.
	 */
	public function pick_active_sender( string $current ): string {
		return $this->resend_ready( $this->license ) ? 'resend' : $current;
	}

	/**
	 * Whether Resend may be the active sender: licensed, key present, domain
	 * verified, AND the From-address covered by that verified domain. The last
	 * check is what stops a "verified" wizard from silently 554-bouncing every
	 * send when the From-address lives on an unverified domain.
	 */
	private function resend_ready( License $license ): bool {
		return $this->credentials_ready( $license )
			&& FromDomainGuard::allows( $this->settings->from_email(), $this->esp->domain() );
	}

	/**
	 * Whether the ESP is fully provisioned (license + key + verified domain),
	 * ignoring the From-address. Separated so the admin notice can tell a
	 * From-address mismatch apart from an unconfigured add-on.
	 */
	private function credentials_ready( License $license ): bool {
		return $license->is_active( Plans::DELIVERABILITY )
			&& $this->esp->has_key()
			&& $this->esp->is_domain_verified();
	}

	/**
	 * True only when the add-on is otherwise ready to send through Resend but the
	 * From-address is on a domain Resend hasn't verified.
	 */
	private function from_domain_mismatch(): bool {
		return $this->credentials_ready( $this->license )
			&& ! FromDomainGuard::allows( $this->settings->from_email(), $this->esp->domain() );
	}
}
