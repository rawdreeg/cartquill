<?php
/**
 * No-op LinkTracker (tracking disabled / tests).
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tracking;

final class NullLinkTracker implements LinkTracker {

	public function pixel_html( int $message_id ): string {
		return '';
	}

	public function wrap_links( string $body, int $message_id ): string {
		return $body;
	}
}
