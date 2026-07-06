<?php
/**
 * In-memory SuppressionList for tests.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

final class ArraySuppressionList implements SuppressionList {

	/** @var array<string, string> "channel:identifier" => reason */
	private array $suppressed = array();

	/**
	 * @param list<string> $seed Email addresses to start suppressed.
	 */
	public function __construct( array $seed = array() ) {
		foreach ( $seed as $email ) {
			$this->suppress( $email );
		}
	}

	public function is_suppressed( string $identifier, string $channel = self::CHANNEL_EMAIL ): bool {
		return isset( $this->suppressed[ $this->key( $identifier, $channel ) ] );
	}

	public function suppress( string $identifier, string $reason = '', string $channel = self::CHANNEL_EMAIL ): void {
		$this->suppressed[ $this->key( $identifier, $channel ) ] = $reason;
	}

	public function remove( string $identifier, string $channel = self::CHANNEL_EMAIL ): void {
		unset( $this->suppressed[ $this->key( $identifier, $channel ) ] );
	}

	private function key( string $identifier, string $channel ): string {
		return $channel . ':' . strtolower( trim( $identifier ) );
	}
}
