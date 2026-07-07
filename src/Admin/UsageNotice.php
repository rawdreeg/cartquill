<?php
/**
 * Admin notice showing monthly action usage against the cap.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Metering\Meter;

/**
 * Surfaces the monthly action meter to admins: a heads-up at 80% of the cap and
 * a hard notice at 100%. Silent below 80% and when metering is effectively
 * unlimited (no configured cap).
 */
final class UsageNotice {

	private const WARN_AT = 80;

	public function __construct( private readonly Meter $meter ) {}

	public function register(): void {
		\add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$limit = $this->meter->limit();
		if ( $limit <= 0 || $limit >= PHP_INT_MAX ) {
			return; // Unmetered.
		}

		$current = $this->meter->current();
		$percent = (int) floor( $current / $limit * 100 );
		if ( $percent < self::WARN_AT ) {
			return;
		}

		if ( $percent >= 100 ) {
			$class   = 'notice-error';
			$message = sprintf(
				/* translators: 1: actions used, 2: monthly cap */
				\__( 'CartQuill has reached its monthly action limit (%1$s of %2$s). Automations resume next month, or upgrade your plan for more.', 'cartquill' ),
				\number_format_i18n( $current ),
				\number_format_i18n( $limit )
			);
		} else {
			$class   = 'notice-warning';
			$message = sprintf(
				/* translators: 1: actions used, 2: monthly cap, 3: percent */
				\__( 'CartQuill has used %1$s of %2$s monthly actions (%3$d%%).', 'cartquill' ),
				\number_format_i18n( $current ),
				\number_format_i18n( $limit ),
				$percent
			);
		}

		printf( '<div class="notice %s"><p>%s</p></div>', \esc_attr( $class ), \esc_html( $message ) );
	}
}
