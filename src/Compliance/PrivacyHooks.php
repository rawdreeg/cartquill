<?php
/**
 * Wires FlowForge personal data into WordPress privacy export/erase tools.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Compliance;

/**
 * Registers a WordPress data exporter and eraser backed by PersonalData, so a
 * store owner can satisfy GDPR data-subject requests with the built-in tools.
 */
final class PrivacyHooks {

	public function __construct( private readonly PersonalData $data ) {}

	public function register(): void {
		\add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		\add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * @param array<string, mixed> $exporters
	 * @return array<string, mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['flowforge'] = array(
			'exporter_friendly_name' => \__( 'FlowForge', 'flowforge' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers
	 * @return array<string, mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['flowforge'] = array(
			'eraser_friendly_name' => \__( 'FlowForge', 'flowforge' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$data = array();
		foreach ( $this->data->export( $email ) as $i => $item ) {
			$data[] = array(
				'group_id'    => $item['group'],
				'group_label' => $item['label'],
				'item_id'     => $item['group'] . '-' . $i,
				'data'        => array(
					array(
						'name'  => $item['label'],
						'value' => $item['value'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$removed  = $this->data->erase( $email );
		$retained = $this->data->is_suppressed( $email );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained,
			'messages'       => $retained
				? array( \__( 'A suppression (opt-out) record was retained to honor the prior unsubscribe.', 'flowforge' ) )
				: array(),
			'done'           => true,
		);
	}
}
