<?php
/**
 * A row in the `flows` table: a flow definition with JSON-encoded steps.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Flow\FlowDefinition;
use CartQuill\Flow\FlowStep;

/**
 * Mirrors the `flows` table. Steps are stored as JSON and decoded into
 * FlowStep objects; `to_definition()` produces the in-memory shape the engine
 * runs. `status` gates enrollment (only `active` flows enroll customers).
 */
final class FlowRecord {

	public const STATUS_DRAFT  = 'draft';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';

	public const SOURCE_TEMPLATE = 'template';
	public const SOURCE_AI       = 'ai';
	public const SOURCE_BUILDER  = 'builder';

	/**
	 * @param int|null       $id     Row id (null until persisted).
	 * @param string         $name   Human-readable name.
	 * @param string         $type   Flow type key (e.g. "abandoned_cart").
	 * @param string         $status One of the STATUS_* constants.
	 * @param string         $source One of the SOURCE_* constants.
	 * @param list<FlowStep> $steps  Ordered steps.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly string $type,
		public readonly string $status,
		public readonly string $source,
		public readonly array $steps,
		public readonly ?string $created_at = null,
	) {}

	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	public function with_id( int $id ): self {
		return new self( $id, $this->name, $this->type, $this->status, $this->source, $this->steps, $this->created_at );
	}

	public function with_status( string $status ): self {
		return new self( $this->id, $this->name, $this->type, $status, $this->source, $this->steps, $this->created_at );
	}

	public function to_definition(): FlowDefinition {
		return new FlowDefinition( $this->id ?? 0, $this->name, $this->type, $this->steps );
	}

	/**
	 * JSON encoding of the steps for the `steps` column.
	 */
	public function steps_json(): string {
		return (string) \wp_json_encode( array_map( static fn( FlowStep $s ) => $s->to_array(), $this->steps ) );
	}

	/**
	 * Decode a JSON steps column into FlowStep objects.
	 *
	 * @return list<FlowStep>
	 */
	public static function decode_steps( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values( array_map( static fn( $s ) => FlowStep::from_array( (array) $s ), $decoded ) );
	}
}
