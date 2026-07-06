<?php
/**
 * One step of a flow: a delay plus the action to run at that point.
 *
 * @package CartQuill
 */

declare(strict_types=1);

namespace CartQuill\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * A single step. `action` names the typed action to run (default `email`);
 * `config` carries that action's settings (Slack channel, spreadsheet id, SMS
 * body, ...). Subject/body remain first-class for the email action so existing
 * templates and {@see \CartQuill\Flow\DefaultFlows} are untouched — a legacy
 * step with no `action` is an email step, and its subject/body double as the
 * email config. Conditions gate the step; the engine gives them meaning.
 *
 * @phpstan-type StepArray array{delay?: int, subject?: string, body?: string, conditions?: array<string, mixed>, action?: string, config?: array<string, mixed>}
 */
final class FlowStep {

	public const ACTION_EMAIL = 'email';

	/**
	 * @param int                  $delay      Seconds to wait before this step, from enrollment/previous step.
	 * @param string               $subject    Subject template (email action).
	 * @param string               $body       Body template (email action, HTML).
	 * @param array<string, mixed> $conditions Send conditions (evaluated by the engine).
	 * @param string               $action     Typed action to run (default "email").
	 * @param array<string, mixed> $config     Per-action configuration.
	 */
	public function __construct(
		public readonly int $delay,
		public readonly string $subject,
		public readonly string $body,
		public readonly array $conditions = array(),
		public readonly string $action = self::ACTION_EMAIL,
		public readonly array $config = array(),
	) {}

	/**
	 * @param StepArray $data
	 */
	public static function from_array( array $data ): self {
		$subject = (string) ( $data['subject'] ?? '' );
		$body    = (string) ( $data['body'] ?? '' );
		$action  = '' !== (string) ( $data['action'] ?? '' ) ? (string) $data['action'] : self::ACTION_EMAIL;

		// Legacy email steps stored subject/body at the top level; surface them in
		// the email action's config too so every action reads its settings the
		// same way, without disturbing the existing top-level fields.
		$config = (array) ( $data['config'] ?? array() );
		if ( self::ACTION_EMAIL === $action && array() === $config ) {
			$config = array(
				'subject' => $subject,
				'body'    => $body,
			);
		}

		return new self(
			(int) ( $data['delay'] ?? 0 ),
			$subject,
			$body,
			(array) ( $data['conditions'] ?? array() ),
			$action,
			$config,
		);
	}

	/**
	 * @return StepArray
	 */
	public function to_array(): array {
		$data = array(
			'delay'      => $this->delay,
			'subject'    => $this->subject,
			'body'       => $this->body,
			'conditions' => $this->conditions,
		);

		// Keep legacy email steps serializing to the exact original shape; only
		// non-email actions (or an explicit config) add the newer keys.
		if ( self::ACTION_EMAIL !== $this->action ) {
			$data['action'] = $this->action;
		}
		if ( array() !== $this->config && self::ACTION_EMAIL !== $this->action ) {
			$data['config'] = $this->config;
		}

		return $data;
	}
}
