<?php
/**
 * Self-hosted open/click tracking: pixel + wrapped links.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tracking;

/**
 * Injects a 1x1 tracking pixel and rewrites http(s) content links so clicks
 * route through the signed redirect. Only absolute http/https links are
 * wrapped — mailto:, tel:, anchors and relative links are left alone (the
 * unsubscribe mailto in particular must not be rewritten).
 */
final class SelfHostedLinkTracker implements LinkTracker {

	public function __construct( private readonly TrackingUrls $urls ) {}

	public function pixel_html( int $message_id ): string {
		if ( $message_id <= 0 ) {
			return '';
		}
		return sprintf(
			'<img src="%s" width="1" height="1" alt="" style="display:none" />',
			$this->escape( $this->urls->open_url( $message_id ) )
		);
	}

	public function wrap_links( string $body, int $message_id ): string {
		if ( $message_id <= 0 ) {
			return $body;
		}

		return (string) preg_replace_callback(
			'/href=(["\'])(https?:\/\/[^"\']+)\1/i',
			function ( array $m ) use ( $message_id ): string {
				$quote  = $m[1];
				$target = htmlspecialchars_decode( $m[2] );
				$wrapped = $this->urls->click_url( $message_id, $target );
				return 'href=' . $quote . $this->escape( $wrapped ) . $quote;
			},
			$body
		);
	}

	private function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES );
	}
}
