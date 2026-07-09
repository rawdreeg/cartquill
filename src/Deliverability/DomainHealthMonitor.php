<?php
/**
 * Periodically re-checks the sending domain's verification state with Resend.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The active-sender gate reads {@see EspSettings::is_domain_verified()} on every
 * request, but nothing updates that flag once a domain is verified. If the store
 * later removes or changes the DNS records, Resend un-verifies the domain and
 * begins rejecting sends — yet CartQuill keeps routing to Resend and bouncing
 * every email. This monitor re-polls Resend on a schedule and writes back the
 * current state, so a drifted domain automatically falls back to wp_mail.
 *
 * A transient API error is deliberately ignored: it must never flip a healthy
 * domain off on a momentary blip.
 */
final class DomainHealthMonitor {

	/** Action Scheduler hook the recurring re-poll fires. */
	public const HOOK = 'cartquill_check_domain_health';

	/** Re-poll cadence (seconds). Deliberately gentle — drift is rare. */
	public const INTERVAL = 21600; // 6 hours.

	public function __construct(
		private readonly EspSettings $esp,
		private readonly ResendClient $client,
	) {}

	public function refresh(): void {
		$domain_id = $this->esp->domain_id();
		if ( '' === $domain_id || ! $this->esp->has_key() ) {
			return; // nothing provisioned to check yet
		}

		try {
			$status = $this->client->domain_status( $domain_id );
		} catch ( ResendException $e ) {
			return; // a momentary API error must not un-verify a healthy domain
		}

		$this->esp->set_domain_verified( $status->verified );
		$this->esp->set_domain_state( $status->state );
	}
}
