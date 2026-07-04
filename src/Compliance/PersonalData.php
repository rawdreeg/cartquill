<?php
/**
 * GDPR export/erase over FlowForge's stored personal data.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Compliance;

use FlowForge\Persistence\CartCaptureStore;
use FlowForge\Persistence\EnrollmentRepository;
use FlowForge\Persistence\MessageRepository;

/**
 * The pure core behind the WordPress privacy exporter/eraser: gather (or delete)
 * everything FlowForge knows about an email — enrollments (with consent source),
 * sent messages, the cart capture, and suppression status.
 */
final class PersonalData {

	public function __construct(
		private readonly EnrollmentRepository $enrollments,
		private readonly MessageRepository $messages,
		private readonly CartCaptureStore $captures,
		private readonly SuppressionList $suppression,
	) {}

	/**
	 * A flat list of {group, label, value} data points for the given email.
	 *
	 * @return list<array{group: string, label: string, value: string}>
	 */
	public function export( string $email ): array {
		$email = strtolower( trim( $email ) );
		$items = array();

		foreach ( $this->enrollments->for_customer( $email ) as $enrollment ) {
			$items[] = array(
				'group' => 'flowforge_enrollments',
				'label' => 'Flow enrollment',
				'value' => sprintf(
					'flow #%d — status %s, consent source: %s',
					$enrollment->flow_id,
					$enrollment->status,
					'' !== $enrollment->source ? $enrollment->source : 'unknown'
				),
			);
		}

		foreach ( $this->messages->for_recipient( $email ) as $message ) {
			$items[] = array(
				'group' => 'flowforge_messages',
				'label' => 'Email sent',
				'value' => sprintf( 'flow #%d step %d — status %s', $message->flow_id, $message->step_index, $message->status ),
			);
		}

		if ( null !== $this->captures->find( $email ) ) {
			$items[] = array(
				'group' => 'flowforge_cart',
				'label' => 'Cart capture',
				'value' => 'An abandoned-cart capture exists for this address.',
			);
		}

		if ( $this->suppression->is_suppressed( $email ) ) {
			$items[] = array(
				'group' => 'flowforge_suppression',
				'label' => 'Suppression',
				'value' => 'This address is on the suppression list (opted out).',
			);
		}

		return $items;
	}

	/**
	 * Delete all FlowForge personal data for the email.
	 *
	 * @return int Number of records removed (enrollments + messages).
	 */
	public function erase( string $email ): int {
		$email   = strtolower( trim( $email ) );
		$removed = $this->enrollments->delete_for_customer( $email );
		$removed += $this->messages->delete_for_recipient( $email );
		$this->captures->delete( $email );
		$this->suppression->remove( $email );
		return $removed;
	}
}
