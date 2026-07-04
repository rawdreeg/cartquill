<?php
/**
 * Builds signed open-pixel and click-redirect URLs.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Tracking;

/**
 * Produces the self-hosted tracking URLs embedded in emails. Everything is
 * signed with the Signer so the endpoint can reject forged requests.
 */
final class TrackingUrls {

	public const PARAM       = 'flowforge_track';
	public const ACTION_OPEN = 'open';
	public const ACTION_CLICK = 'click';

	public function __construct(
		private readonly string $base_url,
		private readonly Signer $signer,
	) {}

	public function open_payload( int $message_id ): string {
		return self::ACTION_OPEN . ':' . $message_id;
	}

	public function click_payload( int $message_id, string $url ): string {
		return self::ACTION_CLICK . ':' . $message_id . ':' . $url;
	}

	public function open_url( int $message_id ): string {
		return $this->build(
			array(
				self::PARAM => self::ACTION_OPEN,
				'mid'       => $message_id,
				't'         => $this->signer->sign( $this->open_payload( $message_id ) ),
			)
		);
	}

	public function click_url( int $message_id, string $url ): string {
		return $this->build(
			array(
				self::PARAM => self::ACTION_CLICK,
				'mid'       => $message_id,
				'url'       => rawurlencode( $url ),
				't'         => $this->signer->sign( $this->click_payload( $message_id, $url ) ),
			)
		);
	}

	/**
	 * @param array<string, string|int> $args
	 */
	private function build( array $args ): string {
		$pairs = array();
		foreach ( $args as $key => $value ) {
			$pairs[] = $key . '=' . $value;
		}
		$separator = str_contains( $this->base_url, '?' ) ? '&' : '?';
		return $this->base_url . $separator . implode( '&', $pairs );
	}
}
