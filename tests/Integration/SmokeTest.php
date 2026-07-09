<?php
/**
 * Confirms the WordPress-integration harness boots: real WP, $wpdb, and the
 * plugin's custom tables are present.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Integration;

use CartQuill\Persistence\Schema;
use WP_UnitTestCase;

final class SmokeTest extends WP_UnitTestCase {

	public function test_wordpress_is_loaded(): void {
		$this->assertTrue( function_exists( 'wp_insert_post' ), 'WordPress core is loaded' );
		$this->assertTrue( defined( 'ABSPATH' ) );
	}

	public function test_plugin_custom_tables_exist(): void {
		global $wpdb;
		$table = Schema::messages_table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		$this->assertSame( $table, $found, 'the messages table was installed by the bootstrap' );
	}
}
