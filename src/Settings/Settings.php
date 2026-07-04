<?php
/**
 * Read access to plugin settings.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Settings;

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
