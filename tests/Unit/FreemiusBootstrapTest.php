<?php
/**
 * The premium Freemius bootstrap.
 *
 * Two properties are asserted here. First, a missing SDK degrades to null rather
 * than fataling — a hand-assembled zip without freemius/ should cost a store its
 * upgrade prompt, not its site. Second, and more importantly, the edition marker
 * does NOT depend on the SDK loading: this file only exists in the premium build,
 * so including it is what makes the build premium, and Freemius stays the plan
 * authority even where the SDK is missing. Keying that off the live SDK instance
 * would mean deleting freemius/ from a copied zip hands the local key store its
 * authority back.
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

	public function test_cartquill_fs_is_a_no_op_without_the_sdk(): void {
		Monkey\setUp();
		// No CARTQUILL_PATH in the DB-free harness, so the bootstrap resolves the SDK
		// path through plugin_dir_path(); point it at a directory with no start.php.
		// (The SDK really is vendored at <repo>/freemius, so this must not resolve
		// to the repository root or the test would boot Freemius for real.)
		Functions\when( 'plugin_dir_path' )->justReturn( '/cartquill-no-such-dir/' );

		require_once dirname( __DIR__, 2 ) . '/src/freemius.php';

		$this->assertTrue( function_exists( 'cartquill_fs' ) );
		$this->assertNull( \cartquill_fs(), 'no SDK on disk = no instance, and no fatal' );

		Monkey\tearDown();
	}

	public function test_including_the_bootstrap_marks_the_build_premium(): void {
		require_once dirname( __DIR__, 2 ) . '/src/freemius.php';

		$this->assertTrue( defined( 'CARTQUILL_FS_IS_PREMIUM' ) );
		$this->assertTrue( CARTQUILL_FS_IS_PREMIUM, 'this file ships only in the premium build' );
	}

	public function test_freemius_owns_the_plan_even_though_the_sdk_did_not_load(): void {
		Monkey\setUp();
		// cartquill_fs() memoizes into a global, but a null result is not `isset`,
		// so the path is resolved again on every call — stub it here too rather
		// than relying on another test having run first.
		Functions\when( 'plugin_dir_path' )->justReturn( '/cartquill-no-such-dir/' );

		require_once dirname( __DIR__, 2 ) . '/src/freemius.php';

		$this->assertTrue( function_exists( 'cartquill_fs_owns_plan' ) );
		$this->assertNull( \cartquill_fs(), 'precondition: the SDK is absent in this harness' );
		$this->assertTrue(
			\cartquill_fs_owns_plan(),
			'a missing SDK must not hand plan authority back to the local key store'
		);

		Monkey\tearDown();
	}
}
