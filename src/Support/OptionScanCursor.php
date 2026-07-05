<?php
/**
 * wp_options-backed ScanCursor.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Parks a scan offset in a non-autoloaded wp_option keyed by name, so several
 * scanners can each keep their own resumable cursor.
 */
final class OptionScanCursor implements ScanCursor {

	public function __construct( private readonly string $option ) {}

	public function get(): int {
		if ( ! function_exists( 'get_option' ) ) {
			return 0;
		}
		return max( 0, (int) \get_option( $this->option, 0 ) );
	}

	public function save( int $offset ): void {
		if ( function_exists( 'update_option' ) ) {
			\update_option( $this->option, max( 0, $offset ), false );
		}
	}

	public function clear(): void {
		if ( function_exists( 'delete_option' ) ) {
			\delete_option( $this->option );
		}
	}
}
