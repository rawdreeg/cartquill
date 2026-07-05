<?php
/**
 * Builds signed one-click unsubscribe URLs.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Tracking\Signer;

/**
 * Produces the per-recipient unsubscribe URL embedded in every email. Signed so
 * the endpoint can only ever be driven for an address we actually mailed —
 * nobody can unsubscribe an arbitrary third party by guessing the link.
 */
final class UnsubscribeLink {

	public const PARAM = 'cartquill_unsubscribe';

	public function __construct(
		private readonly string $base_url,
		private readonly Signer $signer,
	) {}

	public function payload( string $email ): string {
		return 'unsub:' . strtolower( trim( $email ) );
	}

	public function url( string $email ): string {
		$email     = strtolower( trim( $email ) );
		$separator = str_contains( $this->base_url, '?' ) ? '&' : '?';
		return $this->base_url . $separator . http_build_query(
			array(
				self::PARAM => 'confirm',
				'email'     => $email,
				't'         => $this->signer->sign( $this->payload( $email ) ),
			)
		);
	}
}
