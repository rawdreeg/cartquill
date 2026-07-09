<?php
/**
 * Bootstrap for the WordPress-integration test layer (WP_UnitTestCase).
 *
 * Unlike the fast, DB-free unit suite (Brain\Monkey), these tests run against a
 * real WordPress + $wpdb so the genuinely DB/WP-glue-coupled paths — custom-table
 * SQL, option storage — are exercised for real. Run inside the wp-env
 * `tests-cli` container, which provides the WP PHPUnit library at WP_TESTS_DIR.
 *
 * @package CartQuill
 */

declare(strict_types=1);

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test library at {$_tests_dir}.\n" );
	fwrite( STDERR, "Run these via: npm run test:integration (starts wp-env).\n" );
	exit( 1 );
}

// Tell the WP test suite where the DB/ABSPATH config lives (it reads this as a
// PHP constant, not an env var).
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

// Load the plugin before WordPress finishes booting, as a mu-plugin would be.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/cartquill.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

// WordPress is fully loaded now; create the plugin's custom tables so the
// repository tests have real tables to read and write.
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
\CartQuill\Persistence\Schema::install();
