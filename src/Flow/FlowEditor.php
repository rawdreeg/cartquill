<?php
/**
 * Applies editor form data to a flow.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Flow;

use FlowForge\Persistence\FlowRecord;

/**
 * Turns submitted form data into an updated FlowRecord: name, status, and each
 * step's delay / subject / body / exit condition. Pure and validating, so the
 * admin controller stays thin and the transformation is tested directly.
 */
final class FlowEditor {

	private const STATUSES = array(
		FlowRecord::STATUS_DRAFT,
		FlowRecord::STATUS_ACTIVE,
		FlowRecord::STATUS_PAUSED,
	);

	/**
	 * @param array<string, mixed> $input
	 */
	public function apply( FlowRecord $flow, array $input ): FlowRecord {
		$name   = isset( $input['name'] ) ? trim( (string) $input['name'] ) : $flow->name;
		$status = isset( $input['status'] ) && in_array( $input['status'], self::STATUSES, true )
			? (string) $input['status']
			: $flow->status;

		$steps       = array();
		$had_input   = isset( $input['steps'] ) && is_array( $input['steps'] );
		foreach ( (array) ( $input['steps'] ?? array() ) as $raw ) {
			$raw = (array) $raw;

			// A step flagged for removal is dropped, letting the editor delete steps.
			if ( ! empty( $raw['remove'] ) ) {
				continue;
			}

			$conditions = ! empty( $raw['exit_if_ordered'] )
				? array( array( 'type' => 'exit_if_ordered' ) )
				: array();

			$steps[] = new FlowStep(
				delay: max( 0, (int) ( $raw['delay'] ?? 0 ) ),
				subject: (string) ( $raw['subject'] ?? '' ),
				body: (string) ( $raw['body'] ?? '' ),
				conditions: $conditions,
			);
		}

		// Keep the flow's existing steps only when the form submitted no steps
		// key at all (e.g. a name-only update). An explicit empty list clears them.
		if ( ! $had_input ) {
			$steps = $flow->steps;
		}

		return new FlowRecord(
			id: $flow->id,
			name: '' !== $name ? $name : $flow->name,
			type: $flow->type,
			status: $status,
			source: $flow->source,
			steps: $steps,
			created_at: $flow->created_at,
		);
	}
}
