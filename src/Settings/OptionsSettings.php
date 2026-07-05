<?php
/**
 * wp_options-backed Settings.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Reads and writes the plugin settings stored as a single wp_option array.
 * Falls back to the site's admin identity when from-name/email are unset.
 */
final class OptionsSettings implements Settings {

	public const OPTION = 'flowforge_settings';

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

	/**
	 * Whether the store opted in to deleting all FlowForge data on uninstall.
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
