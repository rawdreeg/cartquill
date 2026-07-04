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
		if ( ! isset( $_GET[ UnsubscribeLink::PARAM ] ) ) {
			return;
		}

		$email = isset( $_GET['email'] ) ? \sanitize_email( \wp_unslash( $_GET['email'] ) ) : '';
		$token = isset( $_GET['t'] ) ? \sanitize_text_field( \wp_unslash( $_GET['t'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ok = $this->handle( $email, $token );

		$this->render_confirmation( $ok );
		exit;
	}

	private function render_confirmation( bool $ok ): void {
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
}
