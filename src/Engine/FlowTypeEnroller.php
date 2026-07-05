<?php
/**
 * Enrolls a customer into every active flow of a given type.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use FlowForge\Persistence\FlowRepository;

/**
 * The bridge triggers use: given a flow type and an email, enroll the customer
 * into each active flow of that type (idempotently, via the Enroller). A store
 * with no active flow of the type is a no-op.
 */
final class FlowTypeEnroller {

	public function __construct(
		private readonly FlowRepository $flows,
		private readonly Enroller $enroller,
	) {}

	/**
	 * @param string $source Consent/trigger source recorded on each enrollment.
	 *
	 * @return int Number of enrollments created.
	 */
	public function enroll( string $type, string $email, string $source = '' ): int {
		$created = 0;
		foreach ( $this->flows->active_by_type( $type ) as $flow ) {
			if ( null !== $this->enroller->enroll( $flow, $email, $source ) ) {
				++$created;
			}
		}
		return $created;
	}
}
