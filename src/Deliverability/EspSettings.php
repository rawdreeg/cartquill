<?php
/**
 * Encrypted storage for the customer's Resend credentials + sending domain.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Deliverability;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Security\Crypto;

/**
 * Persists the ESP config in a wp_option, with the API key encrypted at rest via
 * {@see Crypto} (locked rule: credentials never touch the database in the clear).
 * The sending domain is not secret and is stored plainly. Reading a key that
 * fails to decrypt (rotated site salt, tampering) yields an empty string, so the
 * add-on degrades to "not configured" rather than sending with a broken key.
 */
final class EspSettings {

	public const OPTION = 'flowforge_esp';

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
		\update_option( self::OPTION, $data );
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
		\update_option( self::OPTION, $data );
	}

	public function has_webhook_secret(): bool {
		return '' !== $this->webhook_secret();
	}

	public function domain(): string {
		return (string) ( $this->data()['domain'] ?? '' );
	}

	public function set_domain( string $domain ): void {
		$data = $this->data();
		// A changed sending domain invalidates any prior id/verification.
		if ( ( $data['domain'] ?? '' ) !== $domain ) {
			unset( $data['domain_id'], $data['domain_verified'] );
		}
		$data['domain'] = $domain;
		\update_option( self::OPTION, $data );
	}

	public function domain_id(): string {
		return (string) ( $this->data()['domain_id'] ?? '' );
	}

	public function set_domain_id( string $id ): void {
		$data              = $this->data();
		$data['domain_id'] = $id;
		\update_option( self::OPTION, $data );
	}

	public function is_domain_verified(): bool {
		return ! empty( $this->data()['domain_verified'] );
	}

	public function set_domain_verified( bool $verified ): void {
		$data                    = $this->data();
		$data['domain_verified'] = $verified;
		\update_option( self::OPTION, $data );
	}

	public function has_key(): bool {
		return '' !== $this->api_key();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$data = \get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}
}
