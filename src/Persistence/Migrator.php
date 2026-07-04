<?php
/**
 * Applies pending schema migrations on normal requests, not just activation.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

/**
 * WordPress does not fire the activation hook on in-place updates (WP.org
 * auto-update, zip re-upload, git pull), so {@see Schema::install()} would only
 * ever run on a fresh activation and newer columns/indexes would never reach an
 * upgraded install. This runner compares the stored DB version against the
 * code's and re-applies dbDelta when they differ.
 *
 * The version read and the installer are injected so the decision is testable
 * without WordPress.
 */
final class Migrator {

	/** @var callable(): string */
	private $installed_version;

	/** @var callable(): void */
	private $install;

	/**
	 * @param callable(): string|null $installed_version Reads the stored DB version.
	 * @param callable(): void|null    $install           Runs dbDelta + records the version.
	 */
	public function __construct( ?callable $installed_version = null, ?callable $install = null ) {
		$this->installed_version = $installed_version ?? static fn (): string => (string) \get_option( Schema::OPTION_DB_VERSION, '' );
		$this->install           = $install ?? static function (): void {
			Schema::install();
		};
	}

	/**
	 * Run pending migrations when the stored DB version is behind the code's.
	 * A no-op when the schema is already current (a single autoloaded option
	 * read), so it is cheap to call on every request.
	 *
	 * @return bool True when an upgrade ran, false when the schema was current.
	 */
	public function maybe_upgrade(): bool {
		if ( Schema::VERSION === ( $this->installed_version )() ) {
			return false;
		}

		( $this->install )();

		return true;
	}
}
