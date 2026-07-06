<?php
/**
 * The curated library of installable flow templates.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use CartQuill\Persistence\FlowRecord;

/**
 * The set of flows a store can install in one click. Backed by DefaultFlows so
 * the copy and timing are the same production definitions the engine runs.
 */
final class FlowLibrary {

	/**
	 * All templates as unsaved draft FlowRecords.
	 *
	 * @return list<FlowRecord>
	 */
	public function templates(): array {
		$templates = array(
			DefaultFlows::abandoned_cart(),
			DefaultFlows::welcome(),
			DefaultFlows::post_purchase(),
			DefaultFlows::win_back(),
		);

		/**
		 * Let add-ons contribute installable recipes (e.g. the multi-tool
		 * automation add-on's Slack/Sheets/SMS recipes). Callbacks append draft
		 * FlowRecords; non-FlowRecord entries are ignored.
		 *
		 * @param list<FlowRecord> $templates The core flow templates.
		 */
		$templates = \apply_filters( 'cartquill_flow_templates', $templates );

		return array_values( array_filter( (array) $templates, static fn ( $t ) => $t instanceof FlowRecord ) );
	}

	/**
	 * A single template by its flow type, or null if unknown.
	 */
	public function get( string $type ): ?FlowRecord {
		foreach ( $this->templates() as $template ) {
			if ( $template->type === $type ) {
				return $template;
			}
		}
		return null;
	}
}
