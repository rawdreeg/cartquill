<?php
/**
 * Persistence seam for abandoned-cart tracking.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Persistence;

interface CartCaptureStore {

	/**
	 * Record (or refresh) a pending capture for this email at $updated_at.
	 *
	 * Called whenever a customer shows cart activity with a known email. A
	 * previously recovered/enrolled capture is re-opened as pending.
	 */
	public function capture( string $email, string $updated_at ): void;

	/**
	 * Mark a capture recovered (the customer ordered) so it is never enrolled.
	 */
	public function recover( string $email ): void;

	/**
	 * Mark a capture enrolled so the scanner does not enroll it again.
	 */
	public function mark_enrolled( string $email ): void;

	/**
	 * Emails of pending captures last updated at or before $cutoff.
	 *
	 * @return list<string>
	 */
	public function due( string $cutoff ): array;

	public function find( string $email ): ?CartCaptureRecord;

	/**
	 * Delete a capture (GDPR erase).
	 */
	public function delete( string $email ): void;
}
