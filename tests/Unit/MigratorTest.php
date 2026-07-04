<?php
/**
 * The DB migration runner's upgrade decision at its injected seam.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tests\Unit;

use FlowForge\Persistence\Migrator;
use FlowForge\Persistence\Schema;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase {

	public function test_runs_install_when_stored_version_is_behind(): void {
		$installs = 0;
		$migrator = new Migrator(
			static fn (): string => '5',
			static function () use ( &$installs ): void {
				$installs++;
			}
		);

		$this->assertTrue( $migrator->maybe_upgrade(), 'a version mismatch triggers an upgrade' );
		$this->assertSame( 1, $installs );
	}

	public function test_is_a_no_op_when_stored_version_matches(): void {
		$installs = 0;
		$migrator = new Migrator(
			static fn (): string => Schema::VERSION,
			static function () use ( &$installs ): void {
				$installs++;
			}
		);

		$this->assertFalse( $migrator->maybe_upgrade(), 'a current schema is never re-installed' );
		$this->assertSame( 0, $installs );
	}

	public function test_runs_install_on_a_fresh_install_with_no_stored_version(): void {
		$installs = 0;
		$migrator = new Migrator(
			static fn (): string => '',
			static function () use ( &$installs ): void {
				$installs++;
			}
		);

		$this->assertTrue( $migrator->maybe_upgrade() );
		$this->assertSame( 1, $installs );
	}
}
