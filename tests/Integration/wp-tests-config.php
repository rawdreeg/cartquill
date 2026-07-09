<?php
/**
 * WordPress test-suite config for the integration layer.
 *
 * DB credentials + the WordPress path come from environment variables so the
 * same file works under wp-env (its `tests-cli` container sets WORDPRESS_DB_*)
 * and in CI. Point the WP test bootstrap at this file with
 * WP_TESTS_CONFIG_FILE_PATH.
 *
 * @package CartQuill
 */

define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'tests-wordpress' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'tests-mysql' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// A dedicated prefix so the test suite's install never touches the wp-env site's
// own tables in the same database.
$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'CartQuill Integration Tests' );
define( 'WP_PHP_BINARY', 'php' );

// The WordPress install the test suite loads. wp-env mounts core at /var/www/html.
define( 'ABSPATH', getenv( 'WP_TESTS_ABSPATH' ) ?: '/var/www/html/' );
