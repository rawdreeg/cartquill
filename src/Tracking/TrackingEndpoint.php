<?php
/**
 * Handles open-pixel and click-redirect requests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tracking;

use FlowForge\Persistence\MessageRecord;
use FlowForge\Persistence\MessageRepository;

/**
 * Records engagement from the signed tracking URLs.
 *
 * Status only moves forward (sent -> opened -> clicked); a click implies an
 * open. Every request is signature-verified, and the click redirect returns a
 * target only when the URL was one we signed — so this can never be used as an
 * open redirect.
 *
 * Note: because status is a single field, a click supersedes `opened`. The
 * reporting slice therefore counts an open as (opened OR clicked) so clicks
 * are not lost from the open rate.
 */
final class TrackingEndpoint {

	public function __construct(
		private readonly MessageRepository $messages,
		private readonly Signer $signer,
		private readonly TrackingUrls $urls,
	) {}

	public function register(): void {
		\add_action( 'init', array( $this, 'maybe_handle_request' ) );
	}

	/**
	 * Record an open. Returns whether the request was valid.
	 */
	public function handle_open( int $message_id, string $token ): bool {
		if ( ! $this->signer->verify( $this->urls->open_payload( $message_id ), $token ) ) {
			return false;
		}

		$message = $this->messages->find( $message_id );
		if ( null !== $message && MessageRecord::STATUS_SENT === $message->status ) {
			$this->messages->update_status( $message_id, MessageRecord::STATUS_OPENED );
		}
		return true;
	}

	/**
	 * Record a click. Returns the destination URL to redirect to, or null if
	 * the request is forged.
	 */
	public function handle_click( int $message_id, string $url, string $token ): ?string {
		if ( ! $this->signer->verify( $this->urls->click_payload( $message_id, $url ), $token ) ) {
			return null;
		}

		$message = $this->messages->find( $message_id );
		if ( null !== $message
			&& in_array( $message->status, array( MessageRecord::STATUS_SENT, MessageRecord::STATUS_OPENED ), true )
		) {
			$this->messages->update_status( $message_id, MessageRecord::STATUS_CLICKED );
		}
		return $url;
	}

	/**
	 * WordPress entry point: dispatch a tracking request if one is present.
	 */
	public function maybe_handle_request(): void {
		if ( ! isset( $_GET[ TrackingUrls::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action     = \sanitize_text_field( \wp_unslash( $_GET[ TrackingUrls::PARAM ] ) );
		$message_id = isset( $_GET['mid'] ) ? (int) $_GET['mid'] : 0;
		$token      = isset( $_GET['t'] ) ? \sanitize_text_field( \wp_unslash( $_GET['t'] ) ) : '';

		if ( TrackingUrls::ACTION_OPEN === $action ) {
			$this->handle_open( $message_id, $token );
			$this->output_pixel();
		}

		if ( TrackingUrls::ACTION_CLICK === $action ) {
			// PHP already percent-decodes $_GET once; do NOT decode again, or a
			// target containing % / + would no longer match the signed payload.
			$url      = isset( $_GET['url'] ) ? (string) \wp_unslash( $_GET['url'] ) : '';
			$redirect = $this->handle_click( $message_id, $url, $token );

			// The signature guarantees we generated this exact URL, so an
			// external destination is safe to honor; still restrict to http(s).
			if ( null === $redirect || ! preg_match( '/^https?:\/\//i', $redirect ) ) {
				$redirect = \home_url( '/' );
			}
			\wp_redirect( \esc_url_raw( $redirect ) ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function output_pixel(): void {
		\nocache_headers();
		header( 'Content-Type: image/gif' );
		// 1x1 transparent GIF.
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore
		exit;
	}
}
