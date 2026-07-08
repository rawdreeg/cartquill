<?php
/**
 * Shared styling for the CartQuill admin screens.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Enqueues the shared admin stylesheet on CartQuill screens only. Every
 * CartQuill page hook contains the plugin slug (`toplevel_page_cartquill`,
 * `cartquill_page_*`, `admin_page_cartquill-*`), so the hook suffix is the
 * gate; the styles themselves are scoped under `.cartquill-admin`.
 */
final class AdminAssets {

	private const HANDLE = 'cartquill-admin';

	public function register(): void {
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'cartquill' ) ) {
			return;
		}

		\wp_enqueue_style(
			self::HANDLE,
			\plugins_url( 'assets/admin/admin.css', CARTQUILL_FILE ),
			array(),
			CARTQUILL_VERSION
		);
	}
}
