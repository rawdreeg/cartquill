<?php
/**
 * Uninstall entry point.
 *
 * WordPress runs this only when the store owner deletes CartQuill, and it runs
 * standalone — `uninstall_plugin()` includes this file and returns without
 * loading the plugin — so the autoloader has to be pulled in here.
 *
 * The cleanup itself lives in {@see \CartQuill\Uninstaller} rather than here,
 * because WordPress's two uninstall mechanisms are mutually exclusive:
 * `uninstall_plugin()` checks for this file *before* the `uninstall_plugins`
 * option and returns when it finds it, so an extension that needs its own
 * `register_uninstall_hook()` cannot ship this file and must call the class from
 * that hook instead. Both routes run the same code.
 *
 * @package CartQuill
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cartquill_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $cartquill_autoload ) ) {
	// Nothing to clean up safely without the plugin's own classes, and a fatal
	// here would leave the store unable to finish deleting the plugin.
	return;
}

require_once $cartquill_autoload;

\CartQuill\Uninstaller::run();
