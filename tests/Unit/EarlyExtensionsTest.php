<?php
/**
 * The early extension bootstrap.
 *
 * WordPress fires `plugins_loaded` and only then includes the plugin file when a
 * plugin is being activated, so a hook registered from Plugin::boot() is absent
 * from the one request that matters for `register_activation_hook()`. Extensions
 * that need their own activation hook are therefore included while the main
 * plugin file is read, via `src/<Name>/early.php`.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tests\Unit;

use CartQuill\Plugin;
use PHPUnit\Framework\TestCase;

final class EarlyExtensionsTest extends TestCase {

	/** @var list<string> Directories to clean up. */
	private array $temp_dirs = array();

	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			foreach ( array( 'src/Licensing/early.php', 'src/Licensing', 'src', '' ) as $leaf ) {
				$path = rtrim( $dir . '/' . $leaf, '/' );
				if ( is_file( $path ) ) {
					unlink( $path );
				} elseif ( is_dir( $path ) ) {
					rmdir( $path );
				}
			}
		}
		$this->temp_dirs = array();
		parent::tearDown();
	}

	/**
	 * A plugin root containing src/Licensing/early.php, which sets $GLOBALS[$flag].
	 */
	private function fixture_root( string $flag ): string {
		$dir = sys_get_temp_dir() . '/cq-early-' . $flag;
		mkdir( $dir . '/src/Licensing', 0777, true );
		file_put_contents(
			$dir . '/src/Licensing/early.php',
			"<?php\n\$GLOBALS['" . $flag . "'] = true;\n"
		);
		$this->temp_dirs[] = $dir;
		return $dir . '/';
	}

	public function test_it_includes_an_extensions_early_bootstrap(): void {
		$flag = 'cq_early_ran';
		$root = $this->fixture_root( $flag );

		$this->assertArrayNotHasKey( $flag, $GLOBALS, 'precondition: not yet included' );
		Plugin::load_early_extensions( $root );
		$this->assertTrue( $GLOBALS[ $flag ] ?? false, 'src/Licensing/early.php was included' );

		unset( $GLOBALS[ $flag ] );
	}

	public function test_it_is_a_no_op_when_no_extension_is_installed(): void {
		$dir = sys_get_temp_dir() . '/cq-early-empty';
		mkdir( $dir, 0777, true );
		$this->temp_dirs[] = $dir;

		Plugin::load_early_extensions( $dir . '/' );

		$this->addToAssertionCount( 1 ); // Reaching here without error is the assertion.
	}

	public function test_it_does_nothing_rather_than_erroring_without_a_plugin_root(): void {
		Plugin::load_early_extensions( '' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The regression that would silently undo the fix: moving the call inside the
	 * `plugins_loaded` closure looks harmless and puts it back exactly where it
	 * cannot run during an activation request.
	 */
	public function test_the_plugin_file_calls_it_at_file_scope_before_plugins_loaded(): void {
		$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/cartquill.php' );

		$call = strpos( $main, '\CartQuill\Plugin::load_early_extensions();' );
		$this->assertNotFalse( $call, 'cartquill.php calls load_early_extensions()' );

		$hook = strpos( $main, "'plugins_loaded'" );
		$this->assertNotFalse( $hook );
		$this->assertLessThan( $hook, $call, 'the call runs before boot() is even scheduled' );

		$line_start = strrpos( substr( $main, 0, $call ), "\n" );
		$this->assertSame(
			$call,
			false === $line_start ? 0 : $line_start + 1,
			'the call sits at file scope, unindented — inside a closure it would never run during activation'
		);
	}
}
