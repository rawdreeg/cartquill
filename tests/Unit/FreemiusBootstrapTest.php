<?php
/**
 * The Freemius bootstrap is a strict no-op until the SDK is vendored and the
 * product identifiers are defined, so it is safe on every install.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class FreemiusBootstrapTest extends TestCase {

	use MockeryPHPUnitIntegration;

	public function test_cartquill_fs_is_a_no_op_without_the_sdk_and_identifiers(): void {
		Monkey\setUp();
		// No CARTQUILL_PATH in the DB-free harness, so the bootstrap resolves the SDK
		// path through plugin_dir_path(); point it at a directory with no start.php.
		Functions\when( 'plugin_dir_path' )->justReturn( '/cartquill-no-such-dir/' );

		require_once dirname( __DIR__, 2 ) . '/src/freemius.php';

		$this->assertTrue( function_exists( 'cartquill_fs' ) );
		$this->assertNull( \cartquill_fs(), 'no SDK + no identifiers = strict no-op' );

		Monkey\tearDown();
	}
}
