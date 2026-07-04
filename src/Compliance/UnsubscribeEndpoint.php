<?php
/**
 * Handles one-click unsubscribe requests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Compliance;

use FlowForge\Persistence\EnrollmentRepository;
use FlowForge\Tracking\Signer;

/**
 * Unsubscribing suppresses the address globally (honored before every send by
 * the engine) and marks the customer's active enrollments unsubscribed. The
 * signed link means only a genuinely-mailed address can be unsubscribed here.
 */
final class UnsubscribeEndpoint {

	public function __construct(
		private readonly Signer $signer,
		private readonly UnsubscribeLink $link,
		private readonly SuppressionList $suppression,
		private readonly EnrollmentRepository $enrollments,
	) {}

	public function register(): void {
		\add_action( 'init', array( $this, 'maybe_handle_request' ) );
	}

	/**
	 * Suppress the address and unsubscribe its enrollments. Returns whether the
	 * request was valid (correctly signed).
	 */
	public function handle( string $email, string $token ): bool {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! $this->signer->verify( $this->link->payload( $email ), $token ) ) {
			return false;
		}

		$this->suppression->suppress( $email, 'unsubscribe' );
		$this->enrollments->unsubscribe_customer( $email );
		return true;
	}

	public function maybe_handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST[ UnsubscribeLink::PARAM ] ) ) {
			return;
		}

		$email  = isset( $_REQUEST['email'] ) ? \sanitize_email( \wp_unslash( $_REQUEST['email'] ) ) : '';
		$token  = isset( $_REQUEST['t'] ) ? \sanitize_text_field( \wp_unslash( $_REQUEST['t'] ) ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) \wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Only a POST actually opts the user out — so link prefetchers and email
		// scanners that follow the GET link cannot silently unsubscribe anyone.
		// RFC 8058 one-click clients POST here directly.
		if ( 'POST' === $method ) {
			$this->render_result( $this->handle( $email, $token ) );
		} else {
			$this->render_confirm_form( $email, $token );
		}
		exit;
	}

	private function render_result( bool $ok ): void {
		\nocache_headers();
		$message = $ok
			? \__( 'You have been unsubscribed. You will no longer receive these emails.', 'flowforge' )
			: \__( 'This unsubscribe link is invalid or has expired.', 'flowforge' );

		\wp_die(
			\esc_html( $message ),
			\esc_html__( 'Unsubscribe', 'flowforge' ),
			array( 'response' => $ok ? 200 : 400 )
		);
	}

	/**
	 * A GET shows a one-button confirmation form that POSTs back to opt out.
	 */
	private function render_confirm_form( string $email, string $token ): void {
		\nocache_headers();

		$action = $this->link->url( $email ); // same signed URL; the form POSTs to it
		$body   = sprintf(
			'<p>%s</p><form method="post" action="%s"><input type="hidden" name="%s" value="confirm" />'
				. '<input type="hidden" name="email" value="%s" /><input type="hidden" name="t" value="%s" />'
				. '<button type="submit">%s</button></form>',
			\esc_html__( 'Click below to unsubscribe from these emails.', 'flowforge' ),
			\esc_url( $action ),
			\esc_attr( UnsubscribeLink::PARAM ),
			\esc_attr( $email ),
			\esc_attr( $token ),
			\esc_html__( 'Unsubscribe', 'flowforge' )
		);

		\wp_die(
			$body, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
			\esc_html__( 'Unsubscribe', 'flowforge' ),
			array( 'response' => 200 )
		);
	}
}
