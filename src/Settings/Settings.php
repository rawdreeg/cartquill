<?php
/**
 * Read access to plugin settings.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Narrow read seam for the settings the engine needs. Backed by wp_options at
 * runtime and by a plain array in tests.
 */
interface Settings {

	/**
	 * The display name outgoing email is sent from.
	 */
	public function from_name(): string;

	/**
	 * The email address outgoing email is sent from.
	 */
	public function from_email(): string;
}
