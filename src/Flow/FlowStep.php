<?php
/**
 * One step of a flow: a delay plus the email to send at that point.
 *
 * @package FlowForge
 */

declare(strict_types=1);

namespace FlowForge\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A single step. Subject/body are templates rendered per recipient. Conditions
 * are opaque here in the spine; the engine slice (#2) gives them meaning.
 *
 * @phpstan-type StepArray array{delay?: int, subject: string, body: string, conditions?: array<string, mixed>}
 */
final class FlowStep {

	/**
	 * @param int                  $delay      Seconds to wait before this step, from enrollment/previous step.
	 * @param string               $subject    Subject template.
	 * @param string               $body       Body template (HTML).
	 * @param array<string, mixed> $conditions Send conditions (evaluated by the engine).
	 */
	public function __construct(
		public readonly int $delay,
		public readonly string $subject,
		public readonly string $body,
		public readonly array $conditions = array(),
	) {}

	/**
	 * @param StepArray $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['delay'] ?? 0 ),
			(string) $data['subject'],
			(string) $data['body'],
			(array) ( $data['conditions'] ?? array() ),
		);
	}

	/**
	 * @return StepArray
	 */
	public function to_array(): array {
		return array(
			'delay'      => $this->delay,
			'subject'    => $this->subject,
			'body'       => $this->body,
			'conditions' => $this->conditions,
		);
	}
}
