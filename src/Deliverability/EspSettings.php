<?php
/**
 * Encrypted storage for the customer's Resend credentials + sending domain.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Security\Crypto;

/**
 * Persists the ESP config in a wp_option, with the API key encrypted at rest via
 * {@see Crypto} (locked rule: credentials never touch the database in the clear).
 * The sending domain is not secret and is stored plainly. Reading a key that
 * fails to decrypt (rotated site salt, tampering) yields an empty string, so the
 * add-on degrades to "not configured" rather than sending with a broken key.
 */
final class EspSettings {

	public const OPTION = 'cartquill_esp';

	public function __construct( private readonly Crypto $crypto ) {}

	public function api_key(): string {
		$data = $this->data();
		if ( empty( $data['api_key'] ) ) {
			return '';
		}
		return (string) ( $this->crypto->decrypt( (string) $data['api_key'] ) ?? '' );
	}

	public function set_api_key( string $key ): void {
		$data = $this->data();
		if ( '' === $key ) {
			unset( $data['api_key'] );
		} else {
			$data['api_key'] = $this->crypto->encrypt( $key );
		}
		\update_option( self::OPTION, $data, false );
	}

	public function webhook_secret(): string {
		$data = $this->data();
		if ( empty( $data['webhook_secret'] ) ) {
			return '';
		}
		return (string) ( $this->crypto->decrypt( (string) $data['webhook_secret'] ) ?? '' );
	}

	public function set_webhook_secret( string $secret ): void {
		$data = $this->data();
		if ( '' === $secret ) {
			unset( $data['webhook_secret'] );
		} else {
			$data['webhook_secret'] = $this->crypto->encrypt( $secret );
		}
		\update_option( self::OPTION, $data, false );
	}

	public function has_webhook_secret(): bool {
		return '' !== $this->webhook_secret();
	}

	/**
	 * Unix timestamp of the most recent Resend webhook event ingested, or 0 if
	 * none yet — lets the wizard show whether events are actually arriving.
	 */
	public function last_event_at(): int {
		return (int) ( $this->data()['last_event_at'] ?? 0 );
	}

	public function set_last_event_at( int $timestamp ): void {
		$data                  = $this->data();
		$data['last_event_at'] = $timestamp;
		\update_option( self::OPTION, $data, false );
	}

	public function domain(): string {
		return (string) ( $this->data()['domain'] ?? '' );
	}

	public function set_domain( string $domain ): void {
		$data = $this->data();
		// A changed sending domain invalidates any prior id/verification and the
		// DNS records/state captured for the old domain.
		if ( ( $data['domain'] ?? '' ) !== $domain ) {
			unset( $data['domain_id'], $data['domain_verified'], $data['domain_records'], $data['domain_state'] );
		}
		$data['domain'] = $domain;
		\update_option( self::OPTION, $data, false );
	}

	/**
	 * The DNS records the store must add, persisted so they survive the user
	 * leaving to their registrar (they previously lived only in a short transient).
	 *
	 * @return list<array<string, string>>
	 */
	public function domain_records(): array {
		$records = $this->data()['domain_records'] ?? array();
		return is_array( $records ) ? $records : array();
	}

	/**
	 * @param list<array<string, string>> $records
	 */
	public function set_domain_records( array $records ): void {
		$data                   = $this->data();
		$data['domain_records'] = $records;
		\update_option( self::OPTION, $data, false );
	}

	/**
	 * Resend's raw verification state (pending/verified/failed/…), persisted
	 * alongside the records so the wizard can still say "Failed" or "Pending"
	 * after the immediate response is gone.
	 */
	public function domain_state(): string {
		return (string) ( $this->data()['domain_state'] ?? '' );
	}

	public function set_domain_state( string $state ): void {
		$data                 = $this->data();
		$data['domain_state'] = $state;
		\update_option( self::OPTION, $data, false );
	}

	public function domain_id(): string {
		return (string) ( $this->data()['domain_id'] ?? '' );
	}

	public function set_domain_id( string $id ): void {
		$data              = $this->data();
		$data['domain_id'] = $id;
		\update_option( self::OPTION, $data, false );
	}

	public function is_domain_verified(): bool {
		return ! empty( $this->data()['domain_verified'] );
	}

	public function set_domain_verified( bool $verified ): void {
		$data                    = $this->data();
		$data['domain_verified'] = $verified;
		\update_option( self::OPTION, $data, false );
	}

	public function has_key(): bool {
		return '' !== $this->api_key();
	}

	/**
	 * Whether a key is stored but no longer decrypts (e.g. the install key was
	 * lost), so sending has silently fallen back to wp_mail.
	 */
	public function has_undecryptable_key(): bool {
		$data = $this->data();
		return ! empty( $data['api_key'] ) && null === $this->crypto->decrypt( (string) $data['api_key'] );
	}

	/**
	 * Whether a webhook signing secret is stored but no longer decrypts. Unlike a
	 * broken key (which loudly falls back to wp_mail), a broken secret is silent:
	 * the webhook endpoint simply never registers, so delivery/bounce events stop
	 * flowing and addresses stop auto-suppressing with no other symptom.
	 */
	public function has_undecryptable_secret(): bool {
		$data = $this->data();
		return ! empty( $data['webhook_secret'] ) && null === $this->crypto->decrypt( (string) $data['webhook_secret'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$data = \get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}
}
