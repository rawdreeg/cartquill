<?php
/**
 * The one part of the premium layer that cannot wait for `plugins_loaded`.
 *
 * Included by {@see \CartQuill\Plugin::load_early_extensions()} while the main
 * plugin file is being read. Everything else premium adds stays in addon.php,
 * where boot() controls its ordering.
 *
 * The SDK registers its own `register_activation_hook()` during
 * `fs_dynamic_init()`. WordPress fires `plugins_loaded` and only *then* includes
 * the plugin file when activating it, so initialising from boot() means that
 * hook is registered on every request except the one where the plugin is
 * actually activated — and the opt-in the SDK drives from it never fires.
 *
 * Running this early is safe with respect to the admin-menu ordering boot()
 * documents: the SDK hooks `admin_menu` at WP_FS__LOWEST_PRIORITY (999999999),
 * so its own screens are registered after every normally-prioritised callback,
 * including this plugin's own parent menu.
 *
 * @package CartQuill
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

require_once CARTQUILL_PATH . 'src/freemius.php';

if ( function_exists( 'cartquill_fs' ) ) {
	cartquill_fs();
}
