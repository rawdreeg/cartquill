<?php
/**
 * In-memory SuppressionList for tests.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Compliance;

final class ArraySuppressionList implements SuppressionList {

	/** @var array<string, string> email => reason */
	private array $suppressed = array();

	/**
	 * @param list<string> $seed Addresses to start suppressed.
	 */
	public function __construct( array $seed = array() ) {
		foreach ( $seed as $email ) {
			$this->suppress( $email );
		}
	}

	public function is_suppressed( string $email ): bool {
		return isset( $this->suppressed[ $this->normalize( $email ) ] );
	}

	public function suppress( string $email, string $reason = '' ): void {
		$this->suppressed[ $this->normalize( $email ) ] = $reason;
	}

	private function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}
}
