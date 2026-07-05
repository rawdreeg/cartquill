<?php
/**
 * A flow: an ordered list of steps.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Definition-time view of a flow. The persisted `flows` table (built in the
 * engine slice) stores steps as JSON; this is the in-memory shape the engine
 * operates on.
 */
final class FlowDefinition {

	/**
	 * @param int             $id    Flow id (0 for an unsaved/spine flow).
	 * @param string          $name  Human-readable name.
	 * @param string          $type  Flow type key (e.g. "abandoned_cart").
	 * @param list<FlowStep>  $steps Ordered steps.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $type,
		public readonly array $steps,
	) {}

	public function step( int $index ): ?FlowStep {
		return $this->steps[ $index ] ?? null;
	}

	public function step_count(): int {
		return count( $this->steps );
	}
}
