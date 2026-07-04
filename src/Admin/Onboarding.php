<?php
/**
 * Onboarding state: whether the first-run flow is done, and redirect gating.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Admin;

/**
 * Tracks whether a site has completed onboarding (a per-site wp_option, so each
 * client store an agency manages onboards independently) and gates the one-time
 * post-activation redirect.
 */
final class Onboarding {

	public const OPTION_COMPLETE    = 'flowforge_onboarding_complete';
	public const TRANSIENT_REDIRECT = 'flowforge_activation_redirect';

	public function is_complete(): bool {
		return (bool) \get_option( self::OPTION_COMPLETE, false );
	}

	public function mark_complete(): void {
		\update_option( self::OPTION_COMPLETE, true );
	}

	/**
	 * Flag that we should redirect to onboarding on the next admin load
	 * (called from the activation hook).
	 */
	public function flag_for_redirect(): void {
		\set_transient( self::TRANSIENT_REDIRECT, 1, 60 );
	}

	/**
	 * Consume the redirect flag and decide whether to send the user to
	 * onboarding. Returns true at most once, and never once onboarding is done.
	 */
	public function consume_redirect(): bool {
		$pending = (bool) \get_transient( self::TRANSIENT_REDIRECT );
		if ( $pending ) {
			\delete_transient( self::TRANSIENT_REDIRECT );
		}
		return self::should_redirect( $pending, $this->is_complete() );
	}

	/**
	 * Pure decision: redirect only when freshly activated and not yet onboarded.
	 */
	public static function should_redirect( bool $pending, bool $complete ): bool {
		return $pending && ! $complete;
	}
}
