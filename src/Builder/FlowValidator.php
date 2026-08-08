<?php
/**
 * Validates an incoming builder payload against the catalog and builds a FlowRecord.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Flow\FlowStep;
use CartQuill\Persistence\FlowRecord;

/**
 * The write side's gatekeeper: it checks a builder payload against the same
 * {@see BuilderCatalog} the front end rendered from — the flow's trigger, its status,
 * and each step's action (which must be known *and* available — an unavailable
 * action is rejected), its config keys (valid for that action's descriptor, required
 * ones present, numbers numeric, selects within their options), its non-negative
 * delay, and its conditions (known type, valid params). It never trusts the client.
 * Adjusting a pending save is not its job — the `cartquill_flow_presave` filter
 * owns those; and input *sanitization* is applied at the REST boundary (see
 * {@see \CartQuill\Rest\FlowBuilderController::sanitize_payload()}), mirroring how the
 * admin editor sanitizes in `posted_steps()` before the pure transform.
 *
 * {@see self::to_record()} inverts {@see FlowSerializer}: the builder's uniform
 * `{delay, action, config, conditions}` step shape is mapped back to what
 * {@see FlowStep::from_array()} reads (email's subject/body unfolded from config to
 * the top level).
 */
final class FlowValidator {

	private const STATUSES = array( FlowRecord::STATUS_DRAFT, FlowRecord::STATUS_ACTIVE, FlowRecord::STATUS_PAUSED );

	public function __construct( private readonly BuilderCatalog $catalog ) {}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @return list<array{field: string, message: string}> empty when valid
	 */
	public function validate( array $payload ): array {
		$errors        = array();
		$actions       = $this->index( $this->catalog->actions() );
		$conditions    = $this->index( $this->catalog->conditions() );
		$trigger_types = array_column( $this->catalog->triggers(), 'type' );

		if ( '' === trim( (string) ( $payload['name'] ?? '' ) ) ) {
			$errors[] = $this->error( 'name', \__( 'A name is required.', 'cartquill' ) );
		}
		if ( ! in_array( (string) ( $payload['type'] ?? '' ), $trigger_types, true ) ) {
			$errors[] = $this->error( 'type', \__( 'Choose a valid trigger.', 'cartquill' ) );
		}
		if ( ! in_array( (string) ( $payload['status'] ?? FlowRecord::STATUS_DRAFT ), self::STATUSES, true ) ) {
			$errors[] = $this->error( 'status', \__( 'Invalid status.', 'cartquill' ) );
		}

		$steps = $payload['steps'] ?? array();
		if ( ! is_array( $steps ) ) {
			$errors[] = $this->error( 'steps', \__( 'Steps must be a list.', 'cartquill' ) );
			return $errors;
		}

		foreach ( array_values( $steps ) as $index => $step ) {
			$this->validate_step( (int) $index, (array) $step, $actions, $conditions, $errors );
		}

		return $errors;
	}

	/**
	 * Build the record from a payload (call only after {@see self::validate()} is clean).
	 *
	 * @param array<string, mixed> $payload
	 */
	public function to_record( array $payload, ?int $id, string $source, ?string $created_at = null ): FlowRecord {
		$steps = array();
		foreach ( array_values( (array) ( $payload['steps'] ?? array() ) ) as $step ) {
			$steps[] = $this->to_step( (array) $step );
		}

		return new FlowRecord(
			$id,
			trim( (string) ( $payload['name'] ?? '' ) ),
			(string) ( $payload['type'] ?? '' ),
			(string) ( $payload['status'] ?? FlowRecord::STATUS_DRAFT ),
			$source,
			$steps,
			$created_at,
		);
	}

	/**
	 * @param array<string, array<string, mixed>>          $actions
	 * @param array<string, array<string, mixed>>          $conditions
	 * @param list<array{field: string, message: string}>  $errors
	 */
	private function validate_step( int $index, array $step, array $actions, array $conditions, array &$errors ): void {
		$delay = $step['delay'] ?? 0;
		if ( ! is_numeric( $delay ) || (int) $delay < 0 ) {
			$errors[] = $this->error( "steps.$index.delay", \__( 'Delay must be zero or more seconds.', 'cartquill' ) );
		}

		$action = $actions[ (string) ( $step['action'] ?? FlowStep::ACTION_EMAIL ) ] ?? null;
		if ( null === $action ) {
			$errors[] = $this->error( "steps.$index.action", \__( 'Unknown action.', 'cartquill' ) );
			return; // Cannot validate config without a known action descriptor.
		}
		if ( true !== $action['available'] ) {
			$errors[] = $this->error( "steps.$index.action", \__( 'This action is not available yet.', 'cartquill' ) );
			return;
		}

		$this->validate_fields( "steps.$index.config", (array) ( $step['config'] ?? array() ), (array) $action['fields'], $errors );

		foreach ( array_values( (array) ( $step['conditions'] ?? array() ) ) as $ci => $condition ) {
			$this->validate_condition( $index, (int) $ci, (array) $condition, $conditions, $errors );
		}
	}

	/**
	 * @param array<string, mixed>                         $values
	 * @param list<array<string, mixed>>                   $fields
	 * @param list<array{field: string, message: string}>  $errors
	 */
	private function validate_fields( string $prefix, array $values, array $fields, array &$errors ): void {
		$keys = array_column( $fields, 'key' );
		foreach ( $values as $key => $value ) {
			if ( ! in_array( (string) $key, $keys, true ) ) {
				$errors[] = $this->error( "$prefix.$key", \__( 'Unknown setting.', 'cartquill' ) );
			}
		}
		foreach ( $fields as $field ) {
			$key     = (string) $field['key'];
			$present = array_key_exists( $key, $values );
			$value   = $values[ $key ] ?? null;
			$label   = (string) ( $field['label'] ?? $key );
			$type    = (string) ( $field['type'] ?? '' );

			if ( ( $field['required'] ?? false ) && ( ! $present || $this->is_blank( $value ) ) ) {
				/* translators: %s: the field label. */
				$errors[] = $this->error( "$prefix.$key", sprintf( \__( '%s is required.', 'cartquill' ), $label ) );
				continue;
			}
			if ( ! $present || $this->is_blank( $value ) ) {
				continue;
			}
			if ( 'number' === $type && ! is_numeric( $value ) ) {
				/* translators: %s: the field label. */
				$errors[] = $this->error( "$prefix.$key", sprintf( \__( '%s must be a number.', 'cartquill' ), $label ) );
			}
			if ( 'select' === $type && ! in_array( (string) $value, array_map( 'strval', (array) ( $field['options'] ?? array() ) ), true ) ) {
				/* translators: 1: the field label, 2: the allowed values. */
				$errors[] = $this->error( "$prefix.$key", sprintf( \__( '%1$s must be one of: %2$s.', 'cartquill' ), $label, implode( ', ', (array) ( $field['options'] ?? array() ) ) ) );
			}
		}
	}

	/**
	 * @param array<string, mixed>                         $condition
	 * @param array<string, array<string, mixed>>          $conditions
	 * @param list<array{field: string, message: string}>  $errors
	 */
	private function validate_condition( int $index, int $ci, array $condition, array $conditions, array &$errors ): void {
		$descriptor = $conditions[ (string) ( $condition['type'] ?? '' ) ] ?? null;
		if ( null === $descriptor ) {
			$errors[] = $this->error( "steps.$index.conditions.$ci.type", \__( 'Unknown condition.', 'cartquill' ) );
			return;
		}
		// A condition's params share the field-descriptor shape, so reuse the check —
		// but drop the `type` discriminator, which is not one of the params.
		unset( $condition['type'] );
		$this->validate_fields( "steps.$index.conditions.$ci", $condition, (array) $descriptor['params'], $errors );
	}

	private function to_step( array $step ): FlowStep {
		$action = (string) ( $step['action'] ?? FlowStep::ACTION_EMAIL );
		$config = (array) ( $step['config'] ?? array() );

		$data = array(
			'delay'      => (int) ( $step['delay'] ?? 0 ),
			'conditions' => array_values( (array) ( $step['conditions'] ?? array() ) ),
			'action'     => $action,
		);

		if ( FlowStep::ACTION_EMAIL === $action ) {
			// The email action reads subject/body from the top level; unfold from config.
			$data['subject'] = (string) ( $config['subject'] ?? '' );
			$data['body']    = (string) ( $config['body'] ?? '' );
		} else {
			$data['config'] = $config;
		}

		return FlowStep::from_array( $data );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return array<string, array<string, mixed>> keyed by type
	 */
	private function index( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['type'] ] = $row;
		}
		return $out;
	}

	private function is_blank( mixed $value ): bool {
		if ( null === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}
		if ( is_array( $value ) ) {
			return array() === $value;
		}
		return false;
	}

	/**
	 * @return array{field: string, message: string}
	 */
	private function error( string $field, string $message ): array {
		return array(
			'field'   => $field,
			'message' => $message,
		);
	}
}
