<?php
/**
 * Serializes a stored flow into the uniform JSON shape the builder reads.
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
 * The stored step shape is legacy-shaped — an email step keeps its subject/body at
 * the top level and omits the `action`/`config` keys (see {@see FlowStep::to_array()}).
 * The builder wants a *uniform* step: every step is `{delay, action, config, conditions}`,
 * with the email action's subject/body folded into its config, so the front end can
 * render one descriptor-driven form per step regardless of channel. This serializer
 * produces that shape for reads; the write side maps it back through
 * {@see FlowStep::from_array()}.
 */
final class FlowSerializer {

	/**
	 * The full flow for the editor.
	 *
	 * @return array<string, mixed>
	 */
	public function serialize( FlowRecord $flow ): array {
		return array(
			'id'     => $flow->id,
			'name'   => $flow->name,
			'type'   => $flow->type,
			'status' => $flow->status,
			'source' => $flow->source,
			'steps'  => array_map( array( $this, 'serialize_step' ), $flow->steps ),
		);
	}

	/**
	 * A compact row for the flow list.
	 *
	 * @return array<string, mixed>
	 */
	public function summarize( FlowRecord $flow ): array {
		return array(
			'id'         => $flow->id,
			'name'       => $flow->name,
			'type'       => $flow->type,
			'status'     => $flow->status,
			'step_count' => count( $flow->steps ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function serialize_step( FlowStep $step ): array {
		return array(
			'delay'      => $step->delay,
			'action'     => $step->action,
			'config'     => $this->step_config( $step ),
			'conditions' => array_values( $step->conditions ),
		);
	}

	/**
	 * The email action stores its subject/body as top-level step fields; every other
	 * action stores editable settings in `config`. Fold email's into config so the
	 * builder edits one uniform shape.
	 *
	 * @return array<string, mixed>
	 */
	private function step_config( FlowStep $step ): array {
		if ( FlowStep::ACTION_EMAIL === $step->action ) {
			return array(
				'subject' => $step->subject,
				'body'    => $step->body,
			);
		}
		return $step->config;
	}
}
