<?php
/**
 * Adds open/click tracking to a rendered email body.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The compose-side seam. The self-hosted implementation injects a tracking
 * pixel and rewrites content links; the null implementation leaves the body
 * untouched (used in tests and when tracking is off).
 */
interface LinkTracker {

	/**
	 * A tracking pixel `<img>` tied to the message, or '' for none.
	 */
	public function pixel_html( int $message_id ): string;

	/**
	 * Rewrite outbound content links to route through the click redirect.
	 */
	public function wrap_links( string $body, int $message_id ): string;
}
