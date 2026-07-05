<?php
/**
 * wp_options-backed Settings.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Reads and writes the plugin settings stored as a single wp_option array.
 * Falls back to the site's admin identity when from-name/email are unset.
 */
final class OptionsSettings implements Settings {

	public const OPTION = 'cartquill_settings';

	/**
	 * @return array<string, mixed>
	 */
	private function data(): array {
		$data = \get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	public function from_name(): string {
		$data = $this->data();
		if ( ! empty( $data['from_name'] ) ) {
			return (string) $data['from_name'];
		}
		return (string) \get_option( 'blogname', '' );
	}

	public function from_email(): string {
		$data = $this->data();
		if ( ! empty( $data['from_email'] ) ) {
			return (string) $data['from_email'];
		}
		return (string) \get_option( 'admin_email', '' );
	}

	/** Default attribution window in days, used when unset. */
	public const DEFAULT_ATTRIBUTION_DAYS = 7;

	/**
	 * The attribution window in days (last-touch lookback).
	 */
	public function attribution_window_days(): int {
		$days = (int) ( $this->data()['attribution_window_days'] ?? 0 );
		return $days > 0 ? $days : self::DEFAULT_ATTRIBUTION_DAYS;
	}

	/**
	 * Whether the store opted in to deleting all CartQuill data on uninstall.
	 */
	public function remove_data_on_uninstall(): bool {
		return ! empty( $this->data()['remove_data_on_uninstall'] );
	}

	/**
	 * Persist the from-identity.
	 */
	public function update( string $from_name, string $from_email ): void {
		$data               = $this->data();
		$data['from_name']  = $from_name;
		$data['from_email'] = $from_email;
		\update_option( self::OPTION, $data );
	}
}
